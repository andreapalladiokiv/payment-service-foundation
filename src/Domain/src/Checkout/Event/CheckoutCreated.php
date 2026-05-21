<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionPlan;

final readonly class CheckoutCreated implements SerializablePayload
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public Money $amount,
        public ?string $description,
        public ?string $callbackUrl,
        public ?\DateTimeImmutable $expiresAt = null,
        public array $metadata = [],
        public ?SubscriptionPlan $plan = null,
    ) {}

    public function toPayload(): array
    {
        return [
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'description' => $this->description,
            'callback_url' => $this->callbackUrl,
            'expires_at' => $this->expiresAt?->format('Y-m-d\TH:i:s.uP'),
            'metadata' => $this->metadata,
            'plan' => $this->plan?->toArray(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            new Money($payload['amount'], new Currency($payload['currency'])),
            $payload['description'],
            $payload['callback_url'] ?? null,
            isset($payload['expires_at']) ? new \DateTimeImmutable($payload['expires_at']) : null,
            $payload['metadata'] ?? [],
            isset($payload['plan']) ? SubscriptionPlan::fromArray($payload['plan']) : null,
        );
    }
}
