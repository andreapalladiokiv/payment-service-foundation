<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

/**
 * The running count of one poll cycle, filled in as it goes and read once when {@see PollResult}
 * is built.
 *
 * ## Why this is mutable and private to the cycle
 *
 * A poll visits two endpoints and then a list of cases, and each of those steps learns something
 * the result has to report: a case was read, a case was news, a case could not be tied to a
 * payment, a case's `CaseType` is outside the table, a read failed. Threading five by-reference
 * parameters through every helper would put the shape of the result into every signature in the
 * poller; the alternative — a property on the poller — would make a long-lived service carry one
 * cycle's counts into the next, which is a bug that only shows up under Octane and only on the
 * second poll.
 *
 * So the accumulator is created per call, passed down, and discarded. It is `@internal` for the
 * same reason: it is a detail of how {@see DisputePoller} builds its result, not part of what this
 * package offers.
 *
 * @internal
 */
final class PollTally
{
    /** Cases the endpoints returned, before the diff — unchanged ones included. */
    public int $observed = 0;

    /** Cases that were news and reached the recorder, in either of its two forms. */
    public int $emitted = 0;

    /** Of those, the ones that could not be tied to a payment of ours. */
    public int $unmatched = 0;

    /**
     * @var list<PollFailure>
     */
    public array $failures = [];

    /**
     * @var list<array{caseNumber: string, caseType: string}>
     */
    public array $unmappedCaseTypes = [];
}
