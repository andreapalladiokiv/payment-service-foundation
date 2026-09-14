<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute;

/**
 * Where the case stands, as the provider reports it. One of the two axes — see
 * {@see SubmissionState} for the other one and why they are separate.
 *
 * ## The table
 *
 * ```
 * NEEDS_RESPONSE -> UNDER_REVIEW, WON, LOST, ACCEPTED, EXPIRED, CLOSED
 * UNDER_REVIEW   -> WON, LOST, ACCEPTED, CLOSED
 * LOST           -> WON
 * WON / ACCEPTED / EXPIRED / CLOSED -> (nothing)
 * ```
 *
 * Two entries in that table carry most of the weight.
 *
 * **`LOST -> WON` is a real transition**, not a mistake. The networks allow a late win: an
 * issuer may credit outside the normal cycle, and Stripe reports it as exactly that. It is the
 * only way out of `LOST`, and an aggregate that treated `LOST` as terminal would drop money
 * that came back.
 *
 * **`UNDER_REVIEW -> EXPIRED` is absent, and that is deliberate.** Expiry here is a provider
 * signal or the application's own expiry command, and the table names `NEEDS_RESPONSE` as the
 * only state it is reachable from; a case the network has taken under review is not in a window
 * we may declare closed on our own. The guard is left as the table states it rather than
 * widened by inference — a table that can be second-guessed is not a guard — so an expiry
 * attempted on an under-review case is refused loudly and the application has to say what it
 * actually means.
 *
 * The aggregate also recognises `ACCEPTED` followed by a `lost` from the provider as a no-op:
 * see {@see DisputeAggregate::isDeliberateAcceptanceReplayed()}. That rule is about *why* we
 * stopped fighting, so it belongs to the aggregate rather than to this table.
 */
enum DisputeStatus: string
{
    /** The response window is ours to use. */
    case NeedsResponse = 'needs_response';

    /** The network has it. Nothing is waiting on us while it does. */
    case UnderReview = 'under_review';

    case Won = 'won';

    case Lost = 'lost';

    /**
     * We conceded. Reached by our own deliberate close (F7's `POST /v1/disputes/:id/close`),
     * and it records *why* rather than what: the provider will report the same case as `lost`
     * afterwards, because its API knows only that the merchant stopped fighting. The ledger
     * reads this value as loss recognition, which is a different statement from "the issuer
     * decided against us" and the reason this case exists separately from {@see self::Lost}.
     */
    case Accepted = 'accepted';

    case Expired = 'expired';

    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NeedsResponse => [
                self::UnderReview,
                self::Won,
                self::Lost,
                self::Accepted,
                self::Expired,
                self::Closed,
            ],
            self::UnderReview => [self::Won, self::Lost, self::Accepted, self::Closed],
            self::Lost => [self::Won],
            self::Won, self::Accepted, self::Expired, self::Closed => [],
        };
    }

    public function allows(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * No transition out. Read by the application when it decides whether a case is still
     * waiting on anyone — an empty action set means "nothing to do here", and the four
     * terminals plus the two that a human acts on are what that answer is built from.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * A resolution — an outcome the networks decided, as opposed to a position the case is
     * merely sitting in. `WON` and `LOST` are the only two, and they are the only two
     * {@see Event\DisputeResolved} ever carries.
     */
    public function isResolution(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }
}
