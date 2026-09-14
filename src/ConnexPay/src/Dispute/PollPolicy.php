<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use DateInterval;

/**
 * How hard a poll tries, how long it may take, and how much of the last window it reads again.
 *
 * Three deployment facts that are not the poller's to invent, kept together because they are one
 * decision: retrying more costs time the cycle budget has to pay for, and a wider overlap costs
 * nothing but repeats the differ already absorbs. The defaults are a first poll's reasonable
 * guesses; every one of them is a number an operator may need to change after watching the CMS
 * API's real behaviour, and none of them is a constant buried in the poller.
 */
final readonly class PollPolicy
{
    public function __construct(
        /**
         * How many times one read is attempted before its half of the cycle fails.
         *
         * Three, because the CMS API's failures are the ordinary kind — a connection reset, a
         * 503, a slow body — and a fourth attempt costs the next endpoint its share of the cycle.
         */
        public int $attempts = 3,
        /**
         * The delay before the second attempt. The third waits twice this, and so on: exponential
         * in the attempt number, with no jitter. Jitter is deliberately absent — a poll is not a
         * thundering herd, and a deterministic schedule is one a test can assert and an operator
         * can recognise in a log.
         */
        public int $baseBackoffMilliseconds = 250,
        /**
         * The most wall-clock time one cycle may take, across both endpoints, every retry and
         * every case.
         *
         * This is the cap the plan asks for and it is the *only* thing that bounds a cycle: Guzzle
         * bounds a request and the API bounds nothing. When it is reached the cycle stops, the
         * half it stopped in does not advance its window, and the stop is recorded as a failure —
         * so a slow provider delays the cases rather than losing them, and an application that
         * schedules the poll every minute does not accumulate overlapping runs.
         */
        public int $maxCycleSeconds = 60,
        /**
         * How far back the next window reaches before where the last one ended.
         *
         * One day, because the CMS takes dates and not timestamps: a poll running at 10:00 cannot
         * ask for "today from 10:00", so without an overlap a case updated at 11:00 could fall
         * between two windows. The differ makes the repeats free.
         */
        public ?DateInterval $overlap = null,
    ) {}

    public function overlap(): DateInterval
    {
        return $this->overlap ?? new DateInterval('P1D');
    }

    /** The delay before attempt number `$attempt` (the first attempt is not delayed at all). */
    public function backoffMilliseconds(int $attempt): int
    {
        // The cast is not decoration: `**` yields a float once the exponent is large enough, and a
        // multiplication that silently leaves the int domain is how a retry ends up waiting a
        // fractional millisecond. The attempt count is small by construction — the cycle budget
        // bounds the total wait, not this number.
        return $this->baseBackoffMilliseconds * (int) (2 ** max($attempt - 1, 0));
    }
}
