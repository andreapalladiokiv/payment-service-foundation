<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use DateTimeImmutable;

/**
 * One poll cycle, accounted for: what it read, what it reported, and where the next one starts.
 *
 * ## The window is the whole point
 *
 * {@see self::$window} is the cursor the caller stores for next time — the new one, as
 * `poll(PollWindow): PollResult` promises. It is built by advancing each of the two date windows
 * **only if that half was read successfully**, so a failed `GetByResolvedDate` leaves the resolved
 * cursor exactly where it was and the next poll covers the same days again. Nothing that failed to
 * be read can fall out of the window and be lost, which is the only safe way to move a cursor
 * whose purpose is to never miss a case.
 *
 * ## What an alarm watches
 *
 * - `$completed` — both cursors were read. False means at least one failure is in `$failures`.
 * - {@see self::lastSuccessfulPoll()} — the instant of the last cycle that read everything, carried
 *   forward across partial cycles. A stale value with a running schedule is the signature of a
 *   poller that is quietly not working, which is the one thing a poll has in common with nothing
 *   else in this service: no provider is waiting on a response to tell us it never arrived.
 * - `$failures` and `$unmappedCaseTypes` — the defects, in the operator's words rather than in a
 *   log line the caller has to parse.
 *
 * ## The counts
 *
 * `$observed` is how many cases the two endpoints returned, `$emitted` how many of them were new
 * information and reached the recorder, and `$unmatched` how many of those could not be tied to a
 * payment of ours. `observed` minus `emitted` is the diff doing its job; a large difference on a
 * wide window is normal and is not a symptom.
 */
final readonly class PollResult
{
    /**
     * @param list<PollFailure>                                 $failures
     * @param list<array{caseNumber: string, caseType: string}> $unmappedCaseTypes cases whose `CaseType`
     *                                                                             is outside the table — see {@see CaseMapping}
     */
    public function __construct(
        public PollWindow $window,
        public bool $completed,
        public int $observed,
        public int $emitted,
        public int $unmatched,
        public array $failures = [],
        public array $unmappedCaseTypes = [],
    ) {}

    /**
     * The instant up to which both cursors are known to have been read, or null if no cycle has
     * ever completed. Store it and alarm when it stops moving.
     */
    public function lastSuccessfulPoll(): ?DateTimeImmutable
    {
        return $this->window->lastSuccessfulPoll;
    }

    /**
     * The per-case hashes to store next to the cursor: `CaseNumber => hash`, one entry per case
     * ever observed, with this cycle's changes merged in.
     *
     * @return array<string, string>
     */
    public function hashes(): array
    {
        return $this->window->hashes;
    }
}
