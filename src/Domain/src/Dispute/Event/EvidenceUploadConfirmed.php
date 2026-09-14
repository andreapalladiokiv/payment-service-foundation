<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Event;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Domain\Dispute\SubmissionState;

/**
 * The provider acknowledged something we sent. Which of the two things it was is in `from`/`to`.
 *
 * Two shapes, and the split matters more than it looks:
 *
 *  - **`UPLOAD_PENDING -> UPLOAD_PENDING`** — the file was accepted. The state does not move, and
 *    that is deliberate rather than an oversight: an accepted upload is not a filed response, and
 *    moving the case to `SUBMITTED` on the strength of it would record a submission nobody has
 *    sent yet. Nuvei's Image callback is this event; the submission is a separate step
 *    (F8's step 3) and gets its own {@see EvidenceSubmitted}.
 *  - **`SUBMITTED -> CONFIRMED`** — the response was acknowledged: Nuvei's Action callback,
 *    ConnexPay's `HasResponse: true`. This is the only way `CONFIRMED` is ever written.
 *
 * ## What this event never records
 *
 * A withdrawal. Stripe offers no acknowledgement at all, so its cases sit at `SUBMITTED` forever;
 * ConnexPay's `HasResponse` can flip back to `false` on a later poll. Neither is a demotion and
 * neither reaches here — the aggregate absorbs "not yet confirmed" without recording anything, so
 * there is no event in this stream that moves a case backwards on the submission axis. A
 * `SUBMITTED -> CONFIRMED` that appeared twice would be a duplicate delivery, not a second
 * confirmation.
 */
final readonly class EvidenceUploadConfirmed implements SerializablePayload
{
    public function __construct(
        public SubmissionState $from,
        public SubmissionState $to,
        public string $signalKey,
        public DateTimeImmutable $occurredAt,
    ) {}

    /** Whether this acknowledgement was of the file rather than of the filed response. */
    public function wasUploadAcknowledgement(): bool
    {
        return $this->to === SubmissionState::UploadPending;
    }

    /** Whether this acknowledgement is what put the case at `CONFIRMED`. */
    public function wasSubmissionAcknowledgement(): bool
    {
        return $this->to === SubmissionState::Confirmed;
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'from_state' => $this->from->value,
            'to_state' => $this->to->value,
            'signal_key' => $this->signalKey,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    public static function fromPayload(array $payload): static
    {
        return new self(
            SubmissionState::from($payload['from_state']),
            SubmissionState::from($payload['to_state']),
            $payload['signal_key'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }
}
