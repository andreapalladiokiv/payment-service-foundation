<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Domain\Customer\ValueObject\AttachedPaymentMethod;

/**
 * Carries the payment method together with the customer it belongs to.
 *
 * The pairing rather than the bare instrument, so the fact of ownership is in the stream and not
 * only implied by which stream it is in. {@see AttachedPaymentMethod} says why the pairing is a
 * domain type instead of a field on the `Common` value object.
 *
 * No timestamp: every message already has one — EventSauce writes
 * {@see \EventSauce\EventSourcing\Header::TIME_OF_RECORDING} on all of them — so a
 * `registeredAt` in the payload would be a second copy of a fact the stream holds, free to
 * disagree with it. That differs from
 * {@see \Techork\PaymentService\Domain\Subscription\Event\SubscriptionCancelled::$effectiveAt},
 * which is on its event because the moment a cancellation *takes effect* is not the moment it
 * was recorded.
 */
final readonly class PaymentMethodRegistered implements SerializablePayload
{
    public function __construct(
        public AttachedPaymentMethod $attached,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return ['attached' => $this->attached->toPayload()];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(AttachedPaymentMethod::fromPayload($payload['attached']));
    }
}
