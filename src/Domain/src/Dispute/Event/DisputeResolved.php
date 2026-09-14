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
 * The case reached an outcome the networks decided: `WON` or `LOST`.
 *
 * Not "the status changed to something terminal" — `EXPIRED` and `CLOSED` are just as terminal
 * and are not resolutions. This event is what A4 books against, so it is kept to the two values
 * that mean money moved one way or the other, and the constructor refuses anything else rather
 * than letting a caller file an expiry as an outcome.
 *
 * ## The `LOST` to `WON` move is real
 *
 * An issuer may credit outside the normal cycle and Stripe reports it as a late win; `LOST` is
 * therefore not terminal on this aggregate and a `DisputeResolved` carrying `WON` from `LOST` is
 * an ordinary event. `from` is on the payload so that such a case is distinguishable from the
 * ordinary path without folding the stream.
 */
final readonly class DisputeResolved implements SerializablePayload
{
    public function __construct(
        public DisputeStatus $from,
        public DisputeStatus $to,
        public string $signalKey,
        public DateTimeImmutable $occurredAt,
    ) {
        $to->isResolution() || throw new InvalidArgumentException(
            "DisputeResolved cannot carry \"{$to->value}\": only \"won\" and \"lost\" are outcomes the networks decided.",
        );
    }

    /** Whether the money came back to us. The one question every reader of this event asks. */
    public function wasWon(): bool
    {
        return $this->to === DisputeStatus::Won;
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
