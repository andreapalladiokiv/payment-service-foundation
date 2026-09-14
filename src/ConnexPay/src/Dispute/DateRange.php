<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One cursor: the date window a CMS chargeback query is asked about.
 *
 * ## Why a window and not a token
 *
 * The CMS API has no cursor token. The only way to say "what changed since I last looked" is
 * `startDate` / `endDate`, and ConnexPay's documentation spells them as plain dates
 * (`startDate=2016-12-01&endDate=2016-12-01`), not as timestamps. So our cursor *is* a range of
 * days, and every question about it — did it advance, is it stale, what do we ask for — is a
 * question about two dates.
 *
 * ## Dates are UTC, and that is not a detail
 *
 * Both ends are rendered in UTC and the day boundaries are therefore UTC day boundaries. A window
 * formatted from a process's local clock lands a day earlier west of Greenwich, so the same poll
 * would read a different set of cases on two servers — the same hazard
 * {@see ProviderSnapshotCanonicaliser::date()}
 * exists to remove from the hash. Here it would be worse than a hash mismatch: it would silently
 * skip a day of updated cases in one deployment and not the other.
 *
 * ## The overlap, and why the differ makes it harmless
 *
 * {@see self::advancedTo()} moves the window forward but deliberately re-covers the tail: the next
 * window starts an overlap *before* the instant this one ended. Two things make that necessary.
 * The first is the day-granular parameter — a poll that runs at 10:00 cannot ask for "2026-09-14
 * from 10:00", so the end of the day it just read has to be asked for again to be safe. The second
 * is the race between the query and our read: a case updated while the response was in flight can
 * fall outside both windows. Re-reading is cheap and cannot double-report, because every case is
 * hashed and compared against the last hash the caller stored — a window that overlaps is exactly
 * the case that deduplication was built for.
 */
final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        $from <= $to || throw new InvalidArgumentException(sprintf(
            'A poll window cannot end before it starts: it was given %s to %s. The application '
            .'builds the first window and the poller advances it; neither may invert it.',
            $from->format(DATE_ATOM),
            $to->format(DATE_ATOM),
        ));
    }

    /**
     * The query string this window maps onto, in the CMS's own parameter names.
     *
     * The names are `startDate` and `endDate` as documented, and the spelling lives here and
     * nowhere else so that a correction has one place to land.
     *
     * @return array{startDate: string, endDate: string}
     */
    public function query(): array
    {
        return [
            'startDate' => $this->from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
            'endDate' => $this->to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
        ];
    }

    /**
     * The window to ask about next time, once everything in this one has been read.
     *
     * It starts at the overlap before this window's end — not at this window's end, and not at
     * `$at` — so that the tail is covered again. A clock that has not moved, or has moved
     * backwards, must not produce an inverted or a non-advancing range: the window then simply
     * stays where it was and the next poll covers the same ground, which the differ absorbs.
     */
    public function advancedTo(DateTimeImmutable $at, DateInterval $overlap): self
    {
        $to = $at > $this->to ? $at : $this->to;
        $from = $this->to->sub($overlap);

        return new self($from < $to ? $from : $to, $to);
    }
}
