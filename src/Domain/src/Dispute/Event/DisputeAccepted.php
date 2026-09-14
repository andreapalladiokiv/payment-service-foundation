<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Event;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;

/**
 * We conceded: the case is closed from our side and the money is not coming back.
 *
 * Separate from {@see DisputeResolved} because the provider's own words for the same event are
 * different, and the difference is the reason `ACCEPTED` exists as a status at all. F7's
 * `POST /v1/disputes/:id/close` tells Stripe we stopped fighting; Stripe then reports the case as
 * `lost`, because its API has no way to record *why*. What the ledger needs is the why: a loss we
 * chose is a decision to review, and one an issuer handed us is not.
 *
 * Which is also why a `lost` arriving afterwards is absorbed rather than recorded — see
 * `DisputeAggregate::isDeliberateAcceptanceReplayed()`.
 *
 * `signalKey` is always null: a concession is our own call, its response names the case rather
 * than an event, and a repeat of the command is caught by the state it already reached.
 */
final readonly class DisputeAccepted implements SerializablePayload
{
    public function __construct(
        public DisputeStatus $from,
        public ?string $signalKey,
        public DateTimeImmutable $occurredAt,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'from_status' => $this->from->value,
            'signal_key' => $this->signalKey,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            DisputeStatus::from($payload['from_status']),
            $payload['signal_key'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }
}
