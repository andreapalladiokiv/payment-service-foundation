<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway;

use RuntimeException;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * Builds a provider from a credential and configures it, once.
 *
 * The registry maps gateway name to provider class; each registered class must implement
 * {@see Gateway}. Instances are cached per credential, which is safe because a configured driver
 * is immutable in everything that matters: there is no `setParameter` to change it afterwards.
 *
 * Deployment settings win over the tenant's stored credentials and are merged BEFORE the driver is
 * configured — {@see \Techork\PaymentService\Laravel\LaravelGatewayFactory} supplies them. That
 * ordering is a security boundary: `services.{gateway}` is derived from APP_ENV and identical for
 * every tenant, so a stored `environment=production` cannot open a live gateway from a dev build.
 * It used to be applied afterwards and then re-applied by re-initialising, because the first pass
 * had already been baked into clients that could not be reached again.
 */
class GatewayFactory
{
    /** @var array<string, class-string<Gateway>> */
    private array $registry = [];

    /** @var array<string, Gateway> */
    private array $instances = [];

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly DecryptInterface $decrypter,
        private readonly GatewayInstrumentRepository $instruments,
    ) {}

    /**
     * @return array<string, class-string<Gateway>>
     */
    public function all(): array
    {
        return $this->registry;
    }

    /**
     * @param  array<string, class-string<Gateway>>  $registry
     */
    public function replace(array $registry): void
    {
        $this->registry = $registry;
        $this->instances = [];
    }

    public function createForCredential(GatewayCredential $credential): Gateway
    {
        $key = $credential->getId()->toString();

        if (! isset($this->instances[$key])) {
            $class = $this->registry[$credential->getGatewayName()]
                ?? throw new RuntimeException("Gateway '{$credential->getGatewayName()}' is not registered.");

            if (! class_exists($class)) {
                throw new RuntimeException("Class '{$class}' not found.");
            }

            if (! is_a($class, Gateway::class, true)) {
                throw new RuntimeException("Gateway '{$class}' must implement ".Gateway::class.'.');
            }

            $gateway = $this->instantiate($class);
            $gateway->configure(new GatewayInfrastructure(
                $credential,
                $this->decrypter,
                $this->instruments,
                $this->customers,
                [...$credential->getCredentials(), ...$this->settingsFor($credential->getGatewayName())],
            ));

            $this->instances[$key] = $gateway;
        }

        return $this->instances[$key];
    }

    /**
     * Deployment settings for a provider. None here; the Laravel bridge reads
     * `services.{gateway_name}`.
     *
     * @return array<string, mixed>
     */
    protected function settingsFor(string $gatewayName): array
    {
        return [];
    }

    /**
     * @param  class-string<Gateway>  $class  every caller proves this with `is_a()` first, so
     *   stating it here is what lets `new $class` be a Gateway rather than a bare object
     */
    protected function instantiate(string $class): Gateway
    {
        return new $class;
    }
}
