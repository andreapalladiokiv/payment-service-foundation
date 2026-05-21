<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Event;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;

final readonly class PaymentIntentCaptured implements SerializablePayload
{
    public function __construct(
        public Money $capturedAmount,
    ) {}

    public function toPayload(): array
    {
        return [
            'captured_amount' => $this->capturedAmount->getAmount(),
            'captured_currency' => $this->capturedAmount->getCurrency()->getCode(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            new Money($payload['captured_amount'], new Currency($payload['captured_currency'])),
        );
    }
}
