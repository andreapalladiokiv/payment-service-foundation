<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;

/**
 * Carries the id and nothing else.
 *
 * No timestamp: every message already has one — EventSauce writes
 * {@see \EventSauce\EventSourcing\Header::TIME_OF_RECORDING} on all of them — so a
 * `registeredAt` or `detachedAt` in the payload would be a second copy of a fact the stream
 * holds, free to disagree with it. That is different from
 * {@see \Techork\PaymentService\Domain\Subscription\Event\SubscriptionCancelled::$effectiveAt},
 * which is on its event because the moment a cancellation *takes effect* is not the moment it
 * was recorded.
 */
final readonly class PaymentMethodDetached implements SerializablePayload
{
    public function __construct(
        public PaymentMethodId $paymentMethodId,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return ['payment_method_id' => $this->paymentMethodId->toString()];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(PaymentMethodId::fromString($payload['payment_method_id']));
    }
}
