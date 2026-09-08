<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;

/**
 * Everything a gateway is configured with, handed over once when it is built.
 *
 * It replaced three separate setters and an `initialize()` that had to be called twice. The second
 * call existed because Omnipay's `initialize()` RESET the parameter bag, so infrastructure defaults
 * applied afterwards were lost unless the whole thing was re-run — and providers that baked state
 * at initialise time (ConnexPay builds its HTTP clients there) had to be given the chance to bake
 * it again. One configuration step makes that class of bug unexpressible.
 *
 * `$customers` is keyed on OUR customer id. It used to be the instrument-keyed
 * `CustomerRepository`, which is why a driver reading it could only find a provider-side customer
 * for a card we had already stored a reference for — and, failing that, invented one out of
 * whatever address rode along with the payment. A driver asks "which id does this gateway know
 * customer X under" and gets an answer or a null; it no longer has any way to create a person as
 * a side effect of taking money.
 *
 * That swap is also why the three tasks that were planned separately arrived together. The
 * repository used to reach each driver through its own `setCustomerRepository()`, so it could be
 * changed one gateway at a time; it arrives here once, typed, for all of them, so its type cannot
 * be two things while one adapter is migrated and another is not.
 *
 * `$settings` is the merged credential row: what the tenant stored, with the deployment's
 * `services.{gateway}` defaults over the top, so a stored `environment=production` cannot open a
 * live gateway from a dev build. It stays a map because that is what a database row is; what does
 * NOT stay is anything reading it by magic. Each driver's `configure()` names the keys it wants
 * and puts them in typed properties, so a mistyped key is a value that is visibly absent rather
 * than a setter that silently never fired.
 */
final readonly class GatewayInfrastructure
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public GatewayCredential $credential,
        public DecryptInterface $decrypter,
        public GatewayInstrumentRepository $instruments,
        public GatewayCustomerRepository $customers,
        public array $settings = [],
    ) {}

    /**
     * Reads a setting under either spelling.
     *
     * Stored credentials use both — `device_guid` in one row, `deviceGuid` in another — because
     * Omnipay's initializer camel-cased keys on the way in and nothing ever normalised the source.
     * Accepting both is what keeps every existing row loading; doing it in one named method is
     * what keeps it from being magic.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key]
            ?? $this->settings[self::snakeCase($key)]
            ?? $default;
    }

    public function stringSetting(string $key, string $default = ''): string
    {
        $value = $this->setting($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    private static function snakeCase(string $key): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    }
}
