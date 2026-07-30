<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
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
 * local payment method record exists.
 *
 * `billingAddress` is not nullable, and this was the last event where it was.
 * Charge, authorize and requires-action all demand an address, so an intent
 * imported without one was importable and then permanently stuck — nothing could
 * ever finish it. Where the import has no address to give,
 * {@see BillingAddress::unknown()} is the answer: a marker that says "no data",
 * which is the truth, rather than a null that says "this intent is exempt from
 * the rule the rest of the lifecycle enforces".
 */
final readonly class PaymentIntentImported implements SerializablePayload
{
    public function __construct(
        public Money $amount,
        public PaymentIntentStatus $status,
        public PaymentInstrument $instrument,
        public CaptureMethod $captureMethod,
        public BillingAddress $billingAddress,
        public MerchantDescriptor $merchantDescriptor,
        public string $description,
    ) {}

    public function toPayload(): array
    {
        return [
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'status' => $this->status->value,
            'instrument' => $this->instrument->toPayload(),
            'capture_method' => $this->captureMethod->value,
            'billing_address' => $this->billingAddress->toArray(),
            'merchant_descriptor' => (string) $this->merchantDescriptor,
            'description' => $this->description,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            new Money($payload['amount'], new Currency($payload['currency'])),
            PaymentIntentStatus::from($payload['status']),
            PaymentInstrumentFactory::fromPayload($payload['instrument']),
            CaptureMethod::from($payload['capture_method']),
            // Coerced rather than passed straight through: events written before
            // the field was tightened carry null, and the store keeps them
            // forever. Reading one back as the "no data" marker is what it always
            // meant, and is the only alternative to failing every replay of a
            // stream recorded under the old shape.
            $payload['billing_address'] !== null
                ? BillingAddress::fromArray($payload['billing_address'])
                : BillingAddress::unknown(),
            new MerchantDescriptor($payload['merchant_descriptor']),
            $payload['description'],
        );
    }
}
