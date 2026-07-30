<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;

/**
 * Marker instrument for hosted-page flows: the cardholder will enter payment
 * data on the gateway's UI after redirect, not on the merchant side. Carries
 * the URLs where the buyer's browser must return after payment completes —
 * these are part of the "instrument" specification because they determine how
 * the gateway builds the redirect challenge, just like card fields determine
 * how charge requests are built for {@see CreditCard}.
 */
final readonly class HostedPayment implements PaymentInstrument
{
    private const string TYPE = 'hosted';

    public function __construct(
        public string $successUrl,
        public string $cancelUrl,
    ) {}

    /**
     * The instrument for an intent we did not open and therefore know nothing
     * about — an import from a settlement file, or a `created` webhook for an
     * intent placed elsewhere on the gateway account.
     *
     * `PaymentInstrument` is not nullable and an import has to carry something,
     * so this is the honest something: a marker that says the payment data lives
     * gateway-side. The URLs are blank because there is no redirect to make,
     * which {@see isValid()} correctly reports as unusable — nothing may pay
     * with this, and nothing should try.
     */
    public static function unknown(): self
    {
        return new self(successUrl: '', cancelUrl: '');
    }

    #[Override]
    public static function type(): string
    {
        return self::TYPE;
    }

    #[Override]
    public function isValid(): bool
    {
        return filter_var($this->successUrl, FILTER_VALIDATE_URL) !== false
            && filter_var($this->cancelUrl, FILTER_VALIDATE_URL) !== false;
    }

    #[Override]
    public function accept(PaymentInstrumentVisitor $visitor): mixed
    {
        return $visitor->visitHostedPayment($this);
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): self
    {
        return new self(
            successUrl: $payload['success_url'],
            cancelUrl: $payload['cancel_url'],
        );
    }
}
