<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Event;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Override;
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;

/**
 * The response went out — our submission, not the provider's acknowledgement of it.
 *
 * Keeping those two apart is the whole reason the submission axis has both this event and
 * {@see EvidenceUploadConfirmed}. Nuvei's file is uploaded and its callback has not arrived;
 * ConnexPay has an operator who says they filed and a `HasResponse` that still reads false. In
 * both, "we sent it" is true and "they have it" is not, and an event that conflated the two would
 * let a filing be recorded as complete on the strength of a submission nobody acknowledged — the
 * cost of which is a case lost by default, since the response window keeps running either way.
 *
 * `from` is `NONE`, `DRAFT` or `UPLOAD_PENDING`. `NONE` is the ConnexPay operator route: there is
 * no staging step in a portal handoff, and the operator's word is the only evidence that anything
 * was filed. That route is why `NONE -> SUBMITTED` is in the table at all.
 *
 * `signalKey` is null: sending the response is our call, and the moment on the event is when we
 * made it. The provider's acknowledgement of it, where the provider sends one, is a delivery and
 * carries a key — see {@see EvidenceUploadConfirmed}.
 */
final readonly class EvidenceSubmitted implements SerializablePayload
{
    /**
     * @param list<EvidenceType> $types
     */
    public function __construct(
        public SubmissionState $from,
        public SubmissionState $to,
        public array $types,
        public ?string $signalKey,
        public DateTimeImmutable $occurredAt,
    ) {}

    #[Override]
    public function toPayload(): array
    {
        return [
            'from_state' => $this->from->value,
            'to_state' => $this->to->value,
            'types' => array_map(static fn (EvidenceType $type): string => $type->value, $this->types),
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
            array_values(array_map(
                static fn (string $type): EvidenceType => EvidenceType::from($type),
                $payload['types'],
            )),
            $payload['signal_key'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }
}
