<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Event;

use DateTimeImmutable;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;

/**
 * The processor / acquirer fee paid for this PaymentIntent, as observed
 * out-of-band — typically via webhook (Stripe `charge.updated` once
 * `balance_transaction` resolves; Nuvei DMN `feeAmount`) or via batch
 * settlement import (ConnexPay DAF).
 *
 * `observedAt` is when we received the signal, not when the gateway
 * booked the fee. Recorded purely for admin display — the aggregate does
 * not enforce business rules around it.
 */
final readonly class PaymentIntentFeeRecorded implements SerializablePayload
{
    public function __construct(
        public Money $fee,
        public DateTimeImmutable $observedAt,
    ) {}

    public function toPayload(): array
    {
        return [
            'fee_amount' => $this->fee->getAmount(),
            'fee_currency' => $this->fee->getCurrency()->getCode(),
            'observed_at' => $this->observedAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            new Money($payload['fee_amount'], new Currency($payload['fee_currency'])),
            new DateTimeImmutable($payload['observed_at']),
        );
    }
}
