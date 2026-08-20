<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;

/**
 * Carries the payment method itself, because that is what the customer comes to hold.
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
        public PaymentMethod $paymentMethod,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return ['payment_method' => $this->paymentMethod->toPayload()];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(PaymentMethod::fromPayload($payload['payment_method']));
    }
}
