<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;

final readonly class SubscriptionCancelled implements SerializablePayload
{
    public function __construct(
        public string $reason,
    ) {}

    public function toPayload(): array
    {
        return ['reason' => $this->reason];
    }

    public static function fromPayload(array $payload): static
    {
        return new self($payload['reason']);
    }
}
