<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Event;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeFee;

/**
 * One fee charged — or returned — in connection with the case.
 *
 * The whole {@see DisputeFee} rather than a `Money`, because the fee's identity is its type, its
 * amount and the moment it was charged, all three: this aggregate holds a *list* of fees and
 * decides whether a delivery states a new one by comparing triples. An event carrying only the
 * amount could not be folded back into a state that knows which fee it was.
 *
 * Nothing derives a fee from a net position here. Where a provider states fees in a payload the
 * adapter that read it records them; ConnexPay states none, its four fees are known from the
 * operational rules, and A4 derives them from `NetPosition` and the settlement data instead of
 * finding an invented number on this aggregate.
 */
final readonly class DisputeFeeRecorded implements SerializablePayload
{
    public function __construct(
        public DisputeFee $fee,
        public string $signalKey,
        public DateTimeImmutable $occurredAt,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'fee' => $this->fee->toPayload(),
            'signal_key' => $this->signalKey,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            DisputeFee::fromPayload($payload['fee']),
            $payload['signal_key'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }
}
