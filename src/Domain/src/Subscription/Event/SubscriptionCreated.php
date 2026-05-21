<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Event;

use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionPlan;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;

final readonly class SubscriptionCreated implements SerializablePayload
{
    public function __construct(
        public SubscriptionPlan $plan,
        public PaymentMethodId $paymentMethodId,
        public ?string $callbackUrl,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}

    public function toPayload(): array
    {
        return [
            'plan' => $this->plan->toArray(),
            'payment_method_id' => $this->paymentMethodId->toString(),
            'callback_url' => $this->callbackUrl,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            SubscriptionPlan::fromArray($payload['plan']),
            PaymentMethodId::fromString($payload['payment_method_id']),
            $payload['callback_url'] ?? null,
            $payload['metadata'] ?? [],
        );
    }
}
