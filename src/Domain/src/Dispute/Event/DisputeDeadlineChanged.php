<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Event;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;

/**
 * The date the case has to be answered by moved, or was stated for the first time after opening.
 *
 * The date is a plain `DateTimeImmutable` and carries no statement about where it came from. A
 * buffer we subtracted from a figure we read somewhere is a real thing the application does, and
 * nothing records it: no rule in this domain reads it, escalation fires at a fixed offset from
 * whatever date is stored, and the application that computed a buffer knows it computed one.
 *
 * `from` is nullable because a case may have opened with no deadline at all — a provider that
 * raises a case before it has said when it must be answered.
 */
final readonly class DisputeDeadlineChanged implements SerializablePayload
{
    public function __construct(
        public ?DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public ?string $signalKey,
        public DateTimeImmutable $occurredAt,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'from_deadline' => $this->from?->format(DateTimeInterface::ATOM),
            'to_deadline' => $this->to->format(DateTimeInterface::ATOM),
            'signal_key' => $this->signalKey,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            $payload['from_deadline'] === null ? null : new DateTimeImmutable($payload['from_deadline']),
            new DateTimeImmutable($payload['to_deadline']),
            $payload['signal_key'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }
}
