<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Event;

use DateTimeImmutable;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;

/**
 * Processor / acquirer fee booked for a specific refund. Like the
 * payment-intent fee, the signal arrives out-of-band; recorded purely
 * for admin display.
 */
final readonly class RefundFeeRecorded implements SerializablePayload
{
    public function __construct(
        public RefundId $refundId,
        public Money $fee,
        public DateTimeImmutable $observedAt,
    ) {}

    public function toPayload(): array
    {
        return [
            'refund_id' => $this->refundId->toString(),
            'fee_amount' => $this->fee->getAmount(),
            'fee_currency' => $this->fee->getCurrency()->getCode(),
            'observed_at' => $this->observedAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            RefundId::fromString($payload['refund_id']),
            new Money($payload['fee_amount'], new Currency($payload['fee_currency'])),
            new DateTimeImmutable($payload['observed_at']),
        );
    }
}
