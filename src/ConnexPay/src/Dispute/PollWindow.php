<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use DateTimeImmutable;

/**
 * Everything the caller knows about where the last poll left off — handed in, and handed back.
 *
 * ## Both cursors are here, and they are two, not one
 *
 * A ConnexPay case is found by one of two independent date windows, and the plan is explicit that
 * both are required:
 *
 * ```
 * GetByUser?startDate=&endDate=            cases by transaction / update date
 * GetByResolvedDate?startDate=&endDate=    cases by resolution date
 * ```
 *
 * They cannot share a single cursor. A case created six weeks ago can be decided today: it is
 * outside any window that starts where the last poll ended, and it will not appear in the first
 * query at all. A poller with only the first cursor therefore leaves disputes open on our side
 * while the provider has closed them — the failure this class exists to make impossible, and the
 * reason {@see self::$resolved} is a required field rather than an option.
 *
 * ## Why the hashes and the freshness mark are in here too
 *
 * This is the caller's *whole* cursor, not only its dates: the two `DateRange`s, the per-case
 * hashes {@see CaseDiffer} compares against, and the instant of the last poll that read
 * everything. Cursor storage is the application's — this repository owns no scheduler and no
 * store — so the poller neither keeps state between calls nor reaches for it: `poll()` takes one
 * of these and returns the next one. Splitting the hashes out into a second argument, or keeping
 * them on the poller, would put half the cursor in a long-lived service, where a restart or a
 * second worker would lose it and every case in the window would be reported again.
 *
 * The map is **one entry per case number ever observed** and it is deliberately not pruned by the
 * poller: a case that leaves the window and returns — a second chargeback on the same family, a
 * reversal after an appeal — must still be compared against the hash of its last known state, and
 * dropping the entry would present it as brand new. Pruning is the caller's, and pruning a case's
 * entry costs exactly one duplicate report of that case.
 *
 * ## The freshness mark
 *
 * `$lastSuccessfulPoll` is the instant of the last poll that read **both** cursors successfully,
 * carried forward unchanged whenever one of them failed. It is what an alarm watches: a webhook
 * that stops arriving is visible on the provider's side, but a poller that dies or is never
 * scheduled is invisible from here, and "no cases were found" looks identical to "nothing ran".
 * The two windows say the same thing more precisely — a half that failed does not advance, so its
 * `to` stays old — and this says it in one value.
 */
final readonly class PollWindow
{
    /**
     * @param array<string, string> $hashes CaseNumber => the most recent snapshot hash we stored
     */
    public function __construct(
        public DateRange $cases,
        public DateRange $resolved,
        public ?DateTimeImmutable $lastSuccessfulPoll = null,
        public array $hashes = [],
    ) {}

    /**
     * The first window a deployment uses, with both cursors covering the same span.
     *
     * How far back to reach is the caller's decision and not this class's: the plan's own fixture
     * recipe reads a year of cases, because ConnexPay's chargebacks can be worked long after they
     * were raised. Nothing has been polled before, so the freshness mark is null and the hash map
     * is empty — every case in the window is new information by definition.
     */
    public static function covering(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return new self(new DateRange($from, $to), new DateRange($from, $to));
    }
}
