<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute;

/**
 * Our side of the case: where the evidence we intend to file has got to. The second of the two
 * axes, and independent of {@see DisputeStatus} on purpose.
 *
 * The provider's view of the case and our view of our own submission attempt move on different
 * clocks and neither implies the other. Nuvei sits in `UPLOAD_PENDING` — file uploaded, its
 * Image callback not yet received — while the case itself still reads `NEEDS_RESPONSE`. On
 * ConnexPay an operator claims to have submitted in the portal while `HasResponse` is still
 * false, so the case reads `SUBMITTED` while the provider has acknowledged nothing. Folding
 * these into `DisputeStatus` would have meant rewriting every status invariant the first time a
 * submission adapter had an opinion, so they are two axes with two tables.
 *
 * What keeps the axes from touching is that no method on the aggregate advances one because the
 * other moved: {@see DisputeAggregate::attachEvidence()} leaves the status alone, and
 * {@see DisputeAggregate::changeStatus()} leaves this alone.
 */
enum SubmissionState: string
{
    /** Nothing of ours is at the provider. */
    case None = 'none';

    /** Staged at the provider but not filed — Stripe's `submit: false`. Visible to us, invisible to the issuer. */
    case Draft = 'draft';

    /** A file is uploaded and we are waiting for the provider to confirm it accepted the upload. */
    case UploadPending = 'upload_pending';

    /** The response has gone out. Not the same as the provider having acknowledged it. */
    case Submitted = 'submitted';

    /** The provider acknowledged our response. Terminal, and see {@see self::allows()}. */
    case Confirmed = 'confirmed';

    /**
     * The transitions this axis permits, and the reason a change in the other axis never
     * appears here. Anything not listed is refused by the aggregate rather than absorbed.
     *
     * ```
     * NONE           -> DRAFT           evidence staged at the provider (Stripe `submit: false`)
     * NONE           -> SUBMITTED       operator marks a portal submission (ConnexPay only)
     * DRAFT          -> SUBMITTED       the submission call goes out
     * DRAFT          -> UPLOAD_PENDING  file uploaded, awaiting the provider's confirmation
     * UPLOAD_PENDING -> SUBMITTED       the submission is sent for an upload already accepted
     * UPLOAD_PENDING -> DRAFT           the re-query shows the file was never accepted
     * SUBMITTED      -> CONFIRMED       the provider acknowledges the response
     * ```
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::None => [self::Draft, self::Submitted],
            self::Draft => [self::Submitted, self::UploadPending],
            self::UploadPending => [self::Submitted, self::Draft],
            self::Submitted => [self::Confirmed],
            self::Confirmed => [],
        };
    }

    public function allows(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * `Confirmed` is terminal for a reason worth stating: it is the one state that is **never
     * synthesised**. Only a provider's own acknowledgement puts a case here — Nuvei's Image or
     * Action callback, ConnexPay's `HasResponse: true` — and a provider that offers no such
     * signal leaves the case at `Submitted` forever rather than being guessed past. Stripe is
     * that provider. A later "not yet confirmed" reading, such as `HasResponse: false` on a
     * subsequent poll, must leave `Submitted` or `Confirmed` exactly where it is; demoting it
     * would turn a confirmed filing back into an open question.
     */
    public function isTerminal(): bool
    {
        return $this === self::Confirmed;
    }

    public function isAwaitingProvider(): bool
    {
        return $this === self::UploadPending;
    }
}
