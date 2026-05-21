<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

final readonly class SubscriptionActivated implements SerializablePayload
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public \DateTimeImmutable $periodStart,
        public \DateTimeImmutable $periodEnd,
    ) {}

    public function toPayload(): array
    {
        return [
            'payment_intent_id' => $this->paymentIntentId->toString(),
            'period_start' => $this->periodStart->format('Y-m-d\TH:i:s.uP'),
            'period_end' => $this->periodEnd->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            PaymentIntentId::fromString($payload['payment_intent_id']),
            new \DateTimeImmutable($payload['period_start']),
            new \DateTimeImmutable($payload['period_end']),
        );
    }
}
