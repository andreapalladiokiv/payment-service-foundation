<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;

final readonly class SubscriptionCancellationReverted implements SerializablePayload
{
    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload): static
    {
        return new self;
    }
}
