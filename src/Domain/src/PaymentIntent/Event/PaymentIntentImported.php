<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\Customer;
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
 * `customer` is not nullable, and the address it used to be was the last event
 * where it was. Charge, authorize and requires-action all demand one, so an
 * intent imported without it was importable and then permanently stuck — nothing
 * could ever finish it. Where the import has no address to give, the customer's
 * {@see \Techork\PaymentService\Common\ValueObject\BillingAddress::unknown()}
 * is the answer: a marker that says "no data", which is the truth, rather than a
 * null that says "this intent is exempt from the rule the rest of the lifecycle
 * enforces". An import that cannot name the payer at all still has to name one —
 * a settlement file row belongs to somebody, and `CustomerIdentity` takes the
 * shredding stub for a name it does not know.
 */
final readonly class PaymentIntentImported implements SerializablePayload
{
    public function __construct(
        public Money $amount,
        public PaymentIntentStatus $status,
        public PaymentInstrument $instrument,
        public CaptureMethod $captureMethod,
        public Customer $customer,
        public MerchantDescriptor $merchantDescriptor,
        public string $description,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'status' => $this->status->value,
            'instrument' => $this->instrument->toPayload(),
            'capture_method' => $this->captureMethod->value,
            'customer' => $this->customer->toArray(),
            'merchant_descriptor' => (string) $this->merchantDescriptor,
            'description' => $this->description,
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        // Refused by name rather than by the TypeError a missing key would eventually cause.
        // A row written before the event carried a customer cannot be read here, and there is
        // deliberately no coercion for it: the address this replaced could be substituted with
        // `BillingAddress::unknown()` because an absent address is a fact, while an invented
        // payer is indistinguishable from a named one for the rest of the intent's life. So the
        // row is migrated, and this says so.
        isset($payload['customer']) || throw new RuntimeException(
            'A payment intent import carries no customer. Rows written before the customer '
            .'replaced the billing address have to be migrated; nothing here will invent a payer.',
        );

        return new self(
            new Money($payload['amount'], new Currency($payload['currency'])),
            PaymentIntentStatus::from($payload['status']),
            PaymentInstrumentFactory::fromPayload($payload['instrument']),
            CaptureMethod::from($payload['capture_method']),
            Customer::fromArray($payload['customer']),
            new MerchantDescriptor($payload['merchant_descriptor']),
            $payload['description'],
        );
    }
}
