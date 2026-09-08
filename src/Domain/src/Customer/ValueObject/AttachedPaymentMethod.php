<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\ValueObject;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;

/**
 * A payment method together with the customer it belongs to.
 *
 * The pairing is a type here rather than a field on
 * {@see \Techork\PaymentService\Common\ValueObject\PaymentMethod} because of where the two names
 * can be spoken. `CustomerId` implements EventSauce's `AggregateRootId` and `Common` does not
 * depend on eventsauce, so `Common` cannot name it; carrying it there as a `string` would be
 * typing avoided rather than done, and moving `CustomerId` out of the domain to make it nameable
 * would put an aggregate's identity in a package that has no aggregates. Both names exist here,
 * so the pairing lives here.
 *
 * What that buys is a real comparison. {@see \Techork\PaymentService\Domain\Customer\CustomerAggregate::registerPaymentMethod()}
 * checks ownership with `CustomerId::equals()` against its own id — not by comparing strings, and
 * not by trusting the caller to hand the right card to the right customer. A card offered to the
 * wrong customer is refused.
 *
 * `Common\ValueObject\PaymentMethod` stays what it was: the instrument a gateway is given. It
 * knows a card, an address and an id, and deliberately not a customer — a provider package has no
 * business being able to read one off it.
 */
final readonly class AttachedPaymentMethod implements SerializablePayload
{
    public function __construct(
        public CustomerId $customerId,
        public PaymentMethod $paymentMethod,
    ) {}

    public function id(): string
    {
        return $this->paymentMethod->id->toString();
    }

    public function belongsTo(CustomerId $customerId): bool
    {
        return $this->customerId->equals($customerId);
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'customer_id' => $this->customerId->toString(),
            'payment_method' => $this->paymentMethod->toPayload(),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            CustomerId::fromString($payload['customer_id']),
            PaymentMethod::fromPayload($payload['payment_method']),
        );
    }
}
