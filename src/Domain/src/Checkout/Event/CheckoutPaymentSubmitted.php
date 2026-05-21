<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Event;

use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;
use EventSauce\EventSourcing\Serialization\SerializablePayload;

final readonly class CheckoutPaymentSubmitted implements SerializablePayload
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public ?SubscriptionId $subscriptionId = null,
    ) {}

    public function toPayload(): array
    {
        return [
            'payment_intent_id' => $this->paymentIntentId->toString(),
            'subscription_id' => $this->subscriptionId?->toString(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            paymentIntentId: PaymentIntentId::fromString($payload['payment_intent_id']),
            subscriptionId: isset($payload['subscription_id']) ? SubscriptionId::fromString($payload['subscription_id']) : null,
        );
    }
}
