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
 * Evidence was staged at the provider — Stripe's `submit: false`, a response assembled but not yet
 * filed, visible to us and invisible to the issuer.
 *
 * ## One event, three moves: the package's custody
 *
 * The plan's submission table has three transitions that are all "the package is somewhere, and
 * nothing has been filed", and this is the event all three are recorded as, with `from`/`to`
 * saying which way it went:
 *
 * ```
 * NONE           -> DRAFT          staged at the provider (Stripe submit: false)
 * DRAFT          -> UPLOAD_PENDING handed to the provider's upload endpoint
 * UPLOAD_PENDING -> DRAFT          a re-query shows the file was never accepted
 * ```
 *
 * The plan names three events for this axis — `EvidenceAttached`, `EvidenceSubmitted`,
 * `EvidenceUploadConfirmed` — and none of them is a second attachment event, so the alternative
 * would be inventing one. Reading the name as *the package's custody*, rather than as one
 * particular move, is what makes all three fit: the case is never filed by any of them, and
 * `EvidenceSubmitted` is what filing is.
 *
 * ## The key
 *
 * Nullable, and null for all three moves above: staging, uploading and re-querying a rejection are
 * ours as much as the provider's, and a repeat of ours is caught by the state rather than by a
 * delivery key. Nothing here is deduplicated against a provider's event.
 *
 * ## What is recorded, and what deliberately is not
 *
 * The **fact names** are recorded, not their content. The aggregate knows which questions it is
 * answering; it does not hold the delivery note or the support thread. Two reasons, and the first
 * one is the load-bearing one: the content is a base64 PDF or JPEG as often as not, and an event
 * is replayed into every snapshot for the rest of the case's life — a blob in the stream buys the
 * aggregate no answer it asks, and the submission adapters receive the assembled
 * {@see \Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage} through their own
 * ports. The second is that the names are what the operator handoff needs (F9): the person
 * uploading to the ConnexPay portal is told *what* to collect, and the bytes reach them through
 * the application.
 *
 * ## The move back to `DRAFT`
 *
 * `from` can be `UPLOAD_PENDING` and `to` `DRAFT`: a re-query showing the provider never accepted
 * the file puts the package back in our hands rather than leaving the case waiting on an upload
 * that does not exist. It is the one backwards move on either axis, and the reason the submission
 * table is not a chain.
 */
final readonly class EvidenceAttached implements SerializablePayload
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
