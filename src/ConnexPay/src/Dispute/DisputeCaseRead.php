<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use Closure;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use Techork\PaymentService\ConnexPay\ConnexPayDisputesClientInterface;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\Contract\DisputeCaseReading;

/**
 * The CMS read behind {@see \Techork\PaymentService\Gateway\Role\ReadsDisputeCases} — what
 * ConnexPay says about one case, in the terms the layer above asks in.
 *
 * ## There is no "get one case", so this reads a window and picks the case out of it
 *
 * The CMS API has exactly two endpoints and both take a date range: `GetByUser` for cases by when
 * they were raised or updated, `GetByResolvedDate` for cases by when they were decided. Neither
 * takes a case identifier, so a single case cannot be asked for directly and this class does what
 * the poller does — asks a window and looks for the case in the answer. The window is F5's
 * {@see PollWindow} over {@see DateRange}, the same two types the poller advances, because the
 * parameters and their UTC day boundaries are the same fact and two spellings of them would be two
 * chances to get the timezone wrong.
 *
 * `GetByUser` is asked first because it is the window a case that is still open lives in, and
 * `GetByResolvedDate` is asked **only when the first did not contain the case**: a case raised
 * outside the lookback and decided inside it appears in the second window alone, and the caller
 * asking about it deserves the same reading as any other case rather than a "not found". Both
 * windows are read with the same span, so the second read costs one request only in the case the
 * first one missed.
 *
 * ## The lookback is a year, and it is not a literal in an adapter
 *
 * One year is the plan's own fixture recipe — "a year of cases, because ConnexPay's chargebacks can
 * be worked long after they were raised" — and it is the widest span this read can justify: wider
 * buys cases no operator can still be answering, and narrower starts refusing reads on cases that
 * are still open. A case older than that is not readable here at all, and the refusal below says so
 * rather than reporting one.
 *
 * ## A failed read is never a case with nothing open on it
 *
 * Two ways this read can fail, and neither is folded into an answer:
 *
 *  - **the transport refused or the body was unreadable.** {@see ConnexPayDisputesClientInterface}
 *    throws rather than answering `[]` for exactly this reason, and nothing here catches it.
 *  - **the window did not contain the case.** A `RuntimeException` is thrown. The alternative is a
 *    reading of `awaitingResponse: false`, which the layer above reads as "nothing is waiting on
 *    us" — a statement this class would be making about a case it never saw. That is the one
 *    mistake the whole action model exists to prevent, and a case that is about to be lost by
 *    default is precisely the case that would be reported that way.
 *
 * The case that *is* found can fail too, in one way worth naming: a payload with no `CardBrand`,
 * with a brand outside 1-4, or with an unreadable `DueDate` is refused by
 * {@see UnrepresentableCase}, and that refusal propagates. The poller absorbs it per case and
 * records it (F5 has a whole window to get through); a read of one case has nothing to absorb it
 * with, and a case reported with a guessed network would be handed the wrong network's evidence
 * rules.
 *
 * ## What is read, and what is deliberately not derived here
 *
 * `awaitingResponse` is {@see CaseMapping::waitsOnMerchant()} and nothing else. `waitsOnBank()` is
 * **not** folded into it: "the bank must respond" and "nobody is waiting" are different statements
 * and only the first of them is about the bank, so a case reading `B` is not awaiting us and is not
 * misreported as awaiting anyone else either. `S` and `G` are neither. The reading that would come
 * from a second, private reading of `ResolutionTo` is the drift `CaseMapping` exists to prevent.
 *
 * `concedable` is **false, always**, and it is not a placeholder. The field means "closing the case
 * is a call this provider accepts on this one" — Stripe reads it off the case type — and ConnexPay
 * has no such call: the CMS API is read-only, and a case payload states nothing about what the
 * portal would take. Setting it true would offer an irreversible action on a reading nobody can
 * source; the portal link the layer above builds is where an operator finds out for themselves.
 *
 * `cardBrand` travels as the enum's own value (`visa`, `mastercard`, …) and `reasonCode` raw and
 * untrimmed, which is the pair the evidence requirements are keyed on — see
 * {@see DisputeCaseReading} for why neither is the domain's type at this layer.
 */
final readonly class DisputeCaseRead
{
    /** Cases by when they were raised or updated. The reference's first endpoint. */
    private const string PATH_UPDATED = '/api/Chargeback/GetByUser';

    /** Cases by when they were decided — asked only when the first window missed the case. */
    private const string PATH_RESOLVED = '/api/Chargeback/GetByResolvedDate';

    /** How far back the lookup reads, in the CMS's day-granular parameters. */
    private const string LOOKBACK = 'P1Y';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    public function __construct(
        private ConnexPayDisputesClientInterface $client,
        private DisputeCaseQuery $query,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable;
    }

    /**
     * @throws RuntimeException when neither window contains the case
     * @throws UnrepresentableCase when the case is in the window and cannot be stated as a snapshot
     * @throws \InvalidArgumentException when the case is awaiting a response and carries no deadline
     *   — {@see DisputeCaseReading}'s own invariant, which refuses a response task with no date
     */
    public function read(): DisputeCaseReading
    {
        $case = $this->find();

        return new DisputeCaseReading(
            awaitingResponse: CaseMapping::waitsOnMerchant($case->resolutionTo),
            concedable: false,
            cardBrand: $case->cardBrand->value,
            reasonCode: $case->reasonCode,
            // The provider's own `DueDate`, handed up as it stated it. The reading's constructor
            // refuses it being absent on a case that is awaiting a response, and that refusal is the
            // honest one: a case with no window is not a case with no task on it.
            respondBy: $case->dueDate,
        );
    }

    /**
     * The case, from the first of the two windows that contains it.
     *
     * @throws RuntimeException when neither does
     */
    private function find(): CaseSnapshot
    {
        $window = $this->window();

        foreach ([self::PATH_UPDATED => $window->cases, self::PATH_RESOLVED => $window->resolved] as $path => $range) {
            $case = $this->findIn($path, $range);

            if ($case !== null) {
                return $case;
            }
        }

        throw new RuntimeException(sprintf(
            'ConnexPay\'s CMS API did not return case "%s" in either window read for it (%s), so '
            .'what it is still waiting for is unknown. A case that could not be found is not a case '
            .'with nothing open on it — the lookup window is the application\'s to widen, and the '
            .'reference is the case number the poller reports.',
            $this->query->disputeReference,
            $window->cases->from->format('Y-m-d').'..'.$window->cases->to->format('Y-m-d'),
        ));
    }

    /**
     * One window read, with the case picked out of it by its own `CaseNumber`.
     *
     * @return ?CaseSnapshot null when this window does not contain the case
     */
    private function findIn(string $path, DateRange $range): ?CaseSnapshot
    {
        foreach ($this->client->get($path, $range->query()) as $payload) {
            if (self::namesTheCase($payload, $this->query->disputeReference)) {
                return CaseSnapshot::fromPayload($payload);
            }
        }

        return null;
    }

    /**
     * The window to ask about: today back to the lookback, both cursors over the same span.
     *
     * Both ranges are the same span because this is a lookup rather than a poll — there is no cursor
     * to advance and no hash to carry, and the two endpoints are two ways of finding the same case
     * rather than two halves of one cycle. {@see PollWindow::covering()} is F5's own constructor for
     * exactly that first, cursorless window.
     */
    private function window(): PollWindow
    {
        $now = ($this->clock)();

        return PollWindow::covering($now->sub(new DateInterval(self::LOOKBACK)), $now);
    }

    /**
     * Whether a payload is the case the query names.
     *
     * Read off the raw array — both spellings, as {@see CaseSnapshot} reads every field — for the
     * same reason the poller reads the case number off a payload it could not turn into a snapshot:
     * a payload refused by the snapshot builder is still a payload that may be *this* case, and
     * answering "not found" for it would report a refused case as an absent one.
     *
     * Nothing is trimmed or case-folded. The reference is the value the poller stored as the case's
     * own `CaseNumber`, and a near match is a different case.
     *
     * @param array<string, mixed> $payload
     */
    private static function namesTheCase(array $payload, string $caseNumber): bool
    {
        $value = $payload['CaseNumber'] ?? $payload['caseNumber'] ?? null;

        return is_scalar($value) && (string) $value === $caseNumber;
    }
}
