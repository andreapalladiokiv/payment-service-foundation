<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;

/**
 * Bulk-import event for an existing payment intent — typically replays a
 * gateway export or settlement file. Refunds against the imported intent
 * are imported separately as {@see \Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundImported}.
 *
 * `instrument` is the open `PaymentInstrument` contract (not the `PaymentMethod`
 * wrapper) so hosted-page imports can carry a `HostedPayment` marker when no
 * local payment method record exists. `billingAddress` is nullable for the
 * same reason: hosted-flow intents have no merchant-side billing on file.
 */
final readonly class PaymentIntentImported implements SerializablePayload
{
    public function __construct(
        public Money $amount,
        public PaymentIntentStatus $status,
        public PaymentInstrument $instrument,
        public CaptureMethod $captureMethod,
        public ?BillingAddress $billingAddress,
    ) {}

    public function toPayload(): array
    {
        return [
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'status' => $this->status->value,
            'instrument' => $this->instrument->toPayload(),
            'capture_method' => $this->captureMethod->value,
            'billing_address' => $this->billingAddress?->toArray(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            new Money($payload['amount'], new Currency($payload['currency'])),
            PaymentIntentStatus::from($payload['status']),
            PaymentInstrumentFactory::fromPayload($payload['instrument']),
            CaptureMethod::from($payload['capture_method']),
            $payload['billing_address'] !== null ? BillingAddress::fromArray($payload['billing_address']) : null,
        );
    }
}
