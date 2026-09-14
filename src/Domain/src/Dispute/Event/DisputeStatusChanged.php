<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Event;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use InvalidArgumentException;
use Override;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;

/**
 * A change of position that is neither a resolution nor our own concession.
 *
 * `UNDER_REVIEW`, `EXPIRED` and `CLOSED` travel here. The other two outcomes do not, and the
 * split is the plan's own event list rather than a convenience: a resolution the networks decided
 * goes on {@see DisputeResolved} and our deliberate concession on {@see DisputeAccepted}, because
 * the ledger reads all three differently and an event carrying "some new status" would make them
 * one thing to every reader that did not unpack the value.
 *
 * `from` is carried as well as `to` although the aggregate's state knows it: a projection reading
 * the stream forward without folding state needs the pair, and a transition is genuinely a
 * statement about two states — recording only the destination would make "expired from
 * needs_response" and "expired from under_review" the same event, and the second is not a
 * transition the table permits.
 *
 * `signalKey` is nullable because one of the two routes into this event is ours: the application's
 * expiry command closes a window nobody acted in, and there is no delivery whose repeat it could
 * be recognised by. Null says so, rather than naming a key that would mean nothing.
 */
final readonly class DisputeStatusChanged implements SerializablePayload
{
    public function __construct(
        public DisputeStatus $from,
        public DisputeStatus $to,
        public ?string $signalKey,
        public DateTimeImmutable $occurredAt,
    ) {
        $to->isResolution() === false || throw new InvalidArgumentException(
            "DisputeStatusChanged cannot carry \"{$to->value}\": a resolution is recorded as DisputeResolved.",
        );

        $to !== DisputeStatus::Accepted || throw new InvalidArgumentException(
            'DisputeStatusChanged cannot carry "accepted": our own concession is recorded as DisputeAccepted.',
        );
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'from_status' => $this->from->value,
            'to_status' => $this->to->value,
            'signal_key' => $this->signalKey,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            DisputeStatus::from($payload['from_status']),
            DisputeStatus::from($payload['to_status']),
            $payload['signal_key'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }
}
