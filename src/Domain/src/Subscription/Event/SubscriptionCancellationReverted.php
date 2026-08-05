<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;

final readonly class SubscriptionCancellationReverted implements SerializablePayload
{
    #[Override]
    public function toPayload(): array
    {
        return [];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self;
    }
}
