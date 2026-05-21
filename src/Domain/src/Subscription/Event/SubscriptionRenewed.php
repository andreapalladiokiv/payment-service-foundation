<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;

final readonly class SubscriptionRenewed implements SerializablePayload
{
    public function __construct(
        public \DateTimeImmutable $periodStart,
        public \DateTimeImmutable $periodEnd,
    ) {}

    public function toPayload(): array
    {
        return [
            'period_start' => $this->periodStart->format('Y-m-d\TH:i:s.uP'),
            'period_end' => $this->periodEnd->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            new \DateTimeImmutable($payload['period_start']),
            new \DateTimeImmutable($payload['period_end']),
        );
    }
}
