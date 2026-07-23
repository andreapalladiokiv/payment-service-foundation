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
        public ?Money $convertedAmount = null,
    ) {}

    public function toPayload(): array
    {
        return [
            'captured_amount' => $this->capturedAmount->getAmount(),
            'captured_currency' => $this->capturedAmount->getCurrency()->getCode(),
            'converted_amount' => $this->convertedAmount?->getAmount(),
            'converted_currency' => $this->convertedAmount?->getCurrency()->getCode(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            new Money($payload['captured_amount'], new Currency($payload['captured_currency'])),
            isset($payload['converted_amount'])
                ? new Money($payload['converted_amount'], new Currency($payload['converted_currency']))
                : null,
        );
    }
}
