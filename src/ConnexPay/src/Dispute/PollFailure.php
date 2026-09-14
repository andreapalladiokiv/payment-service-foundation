<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

/**
 * Something the poll could not do, reported on the result instead of thrown.
 *
 * ## Why this is a value and not an exception
 *
 * The poller reads two cursors and then reports what it found; a failure of one of them is not a
 * reason to abandon the other, and it is not a reason to hand the caller nothing. So a failure is
 * carried as a value on {@see PollResult}, and the poller returns normally. Two consequences are
 * deliberate:
 *
 * - **The window does not advance for the half that failed.** That is what makes a failed read
 *   safe: the cases it would have covered are read again next cycle rather than skipped. A thrown
 *   exception would do the same thing by losing the result entirely, which is also why it is not
 *   the mechanism — the caller could not then store the freshness mark or alarm on the count.
 * - **Nothing here is silent.** The application can alarm on `$failures !== []` and on the
 *   freshness mark, and the message names the endpoint, the attempt count and the provider's own
 *   words. A poll that dies quietly is the failure mode this whole design is arranged against.
 *
 * ## The three things that end up here
 *
 * - a **read** that failed after every retry, or that the cycle budget did not leave time for
 *   (`$caseNumber` null, `$attempts` counting the tries actually made);
 * - a **case that cannot be represented** — the CMS's `CardBrand` 5 (PayPal) has no counterpart in
 *   `Common\ValueObject\CardBrand`, and the reference forbids guessing one; a case missing its
 *   `CaseNumber` or `ReasonCode` has no identity to report it under;
 * - a **recorder that answered `NotFound`** — the payment the case names has not been observed
 *   yet, so the case will be offered again on the next poll.
 *
 * In every one of those cases the case's hash is **not** stored, which is what makes the last two
 * re-report rather than vanish.
 */
final readonly class PollFailure
{
    public function __construct(
        public string $endpoint,
        public ?string $caseNumber,
        public string $reason,
        public int $attempts = 1,
    ) {}

    /**
     * The case this failure is about, or the endpoint when it is about the read itself.
     *
     * A single line for a log or an alert: which call, which case, how many tries, what happened.
     */
    public function describe(): string
    {
        return sprintf(
            '%s%s failed after %d attempt(s): %s',
            $this->endpoint,
            $this->caseNumber === null ? '' : ' (case '.$this->caseNumber.')',
            $this->attempts,
            $this->reason,
        );
    }
}
