<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;


/**
 * Turns "the provider returned this case again" into "is this news?" — by comparing a case's
 * snapshot hash against the **single most recent** hash we hold for it.
 *
 * ## Why a hash at all
 *
 * The CMS API has no event id. A delivery from it is not an event: it is the case as it currently
 * stands, and a case can be returned unchanged by both cursors on every poll for twelve days
 * without anything having happened. Everything the aggregate needs to be idempotent is therefore
 * derived from the state itself, and {@see ProviderSnapshotCanonicaliser} is the contract that
 * makes two statements of the same state hash to the same key. This class is the comparison half
 * of that: hash, look up the last one, decide.
 *
 * ## One hash per case, never a set of hashes ever seen
 *
 * The stored value is replaced, not accumulated, and that is the whole reason this class exists in
 * F5's file list rather than a `Set`:
 *
 * ```
 * first chargeback      -> hash A   -> reported
 * we respond            -> hash B   -> reported (HasResponse flipped)
 * bank rejects us       -> hash A   -> reported again  <-- a set would suppress this
 * ```
 *
 * A case legitimately returns to a combination of values it held before — that is what the
 * `HasResponse` and `WinLoss` mappings exist to report — and a set of every hash ever seen would
 * file the third line above as a duplicate of the first and drop the fact that our response was
 * refused. One hash per case, compared against the most recent, is the contract F1 states and this
 * is where it is kept.
 *
 * ## It also absorbs the overlap
 *
 * The windows deliberately overlap and the two cursors deliberately return the same case twice in
 * one cycle, and both are free: the second statement of an unchanged case hashes the same, the
 * second statement of a changed one is a second report in the same cycle (which the aggregate's
 * own idempotency key absorbs by key). That is the trade the diff makes, and it is much cheaper
 * than a window narrow enough to never repeat.
 *
 * ## The hash is recomputed, never passed in
 *
 * {@see self::accept()} hashes the case again rather than taking a hash from its caller. The
 * computation is eleven fields and a sha256, the canonicaliser is stateless, and the alternative
 * is an API where a caller can store a hash that does not belong to the case it names — a class of
 * bug with no symptom until a case stops being reported.
 */
final class CaseDiffer
{
    /**
     * The most recent hash per case, seeded with the caller's store and updated as cases are
     * accepted.
     *
     * @var array<string, string>
     */
    private array $known;

    /**
     * @param array<string, string> $known CaseNumber => hash, from {@see PollWindow::$hashes}
     */
    public function __construct(
        private readonly ProviderSnapshotCanonicaliser $canonicaliser,
        private readonly string $currency,
        array $known = [],
    ) {
        $this->known = $known;
    }

    /**
     * The case's key, or null when it has not moved since the most recent hash we hold.
     *
     * A case we have never seen — no stored hash under its `CaseNumber` — is news by definition,
     * which is how a backlog import reports every case in its window exactly once.
     */
    public function changed(CaseSnapshot $case): ?string
    {
        $hash = $this->hash($case);

        return ($this->known[$case->caseNumber] ?? null) === $hash ? null : $hash;
    }

    /**
     * Records the case's hash as the most recent one.
     *
     * Called **after** the case has reached the recorder, and only then. A case whose hash is
     * stored before it is reported is a case that will never be reported: the next poll finds it
     * unchanged. That ordering is why this is a separate call rather than a side effect of
     * {@see self::changed()} — the two answers are not the same answer, and the difference is a
     * silently lost dispute.
     */
    public function accept(CaseSnapshot $case): void
    {
        $this->known[$case->caseNumber] = $this->hash($case);
    }

    /**
     * Everything known after the cycle: the caller's hashes with this cycle's accepted ones merged
     * in, and nothing pruned.
     *
     * Pruning is deliberately not done here. A case that leaves the window and comes back — a
     * second chargeback on the same family, a reversal after an appeal — has to be compared
     * against its last known state, and an entry dropped for being out of the window would present
     * it as brand new. The caller may prune, at the cost of one duplicate report per pruned case.
     *
     * @return array<string, string>
     */
    public function known(): array
    {
        return $this->known;
    }

    /** The canonical key for a case, over the field list F1 fixes. */
    public function hash(CaseSnapshot $case): string
    {
        return $this->canonicaliser->canonicalise($case->hashFields($this->currency));
    }
}
