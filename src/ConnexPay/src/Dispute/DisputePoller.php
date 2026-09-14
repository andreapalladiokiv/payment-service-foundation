<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use Closure;
use DateTimeImmutable;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\ConnexPayDisputesClientInterface;
use Techork\PaymentService\ConnexPay\ConnexPaySettings;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\UnmatchedDispute;
use Throwable;

/**
 * Reads ConnexPay's chargeback cases and reports what changed — as a service the application
 * schedules, not as a job.
 *
 * ## A poll, because ConnexPay has no webhook for this
 *
 * The CMS API is read-only and pushes nothing. Every dispute fact from this gateway therefore
 * arrives when *we* ask, which makes this package's half of the work a pair of reads and a diff —
 * and makes the failure mode the opposite of a webhook's. A webhook that stops being delivered is
 * visible on the provider's side and retried by it; a poll that is never scheduled, or that dies
 * quietly, looks exactly like a gateway with nothing to report. That is why every fact about the
 * cycle is on {@see PollResult} — the freshness mark, the failures, the window that did not
 * advance — rather than only in a log.
 *
 * ## Both cursors, every cycle, and why neither may be skipped
 *
 * ```
 * /api/Chargeback/GetByUser?startDate=&endDate=          cases by when they were raised or updated
 * /api/Chargeback/GetByResolvedDate?startDate=&endDate=  cases by when they were decided
 * ```
 *
 * A case raised six weeks ago can be decided today. It is outside any window that starts where the
 * last poll ended, and the first query will never mention it again — so a poller that reads only
 * the first cursor leaves disputes open on our side forever while ConnexPay has already closed
 * them, and the money has already moved. Both reads are attempted on every cycle, and each one's
 * window advances only if that read succeeded: a failed `GetByResolvedDate` re-covers the same
 * days next time instead of skipping them.
 *
 * ## What a case becomes
 *
 * Cases are matched to our payments by `SaleGuid` first — ConnexPay's own guid for the sale the
 * case is against, and the value the payment's `gateway_references` row holds once the sale is
 * confirmed — then by `OrderNumber`, the value we sent as `clientUniqueId` (our PaymentIntent id),
 * and last by `Arn`. {@see self::match()} states what each key's standing actually is; the point
 * worth repeating here is that the old ranking had it backwards, and a card-data sale — the
 * ordinary case — matched on neither key the poller tried. A case matching none of the three is
 * **not dropped and not retried forever**: it goes out through
 * {@see GatewayDisputeRecorder::onUnmatchedDispute()} with everything the payload carried, and its
 * hash is stored like any other, so the operator is given it once. A case that matches is reported
 * through {@see GatewayDisputeRecorder::onDisputeObserved()} with the whole snapshot, whether it
 * is new or already decided — the snapshot carries the status, so a case that resolved before we
 * ever saw it can be opened straight into a terminal state.
 *
 * All three keys resolve through the same {@see TransactionIdResolver}, which matches a stored
 * reference exactly: it recognises a value a `gateway_references` row holds, and never our own
 * aggregate id. Which of the three that is depends on how far the payment has got, which is why
 * the order matters rather than any one key being tried alone.
 *
 * `onDisputeResolved()` is deliberately **not** called from here. That call exists for a
 * resolution that arrives *alone*, addressed by the provider's own reference (Stripe's
 * `charge.dispute.closed`); ConnexPay never sends one, because a CMS read always returns the whole
 * case, and the same fact reaches the aggregate through the observed path with more of the case
 * attached to it.
 *
 * ## Retries, the budget, and the order of operations that keeps cases safe
 *
 * A read is attempted {@see PollPolicy::$attempts} times with exponential backoff. The cycle
 * budget is checked before every attempt and before every case, so a hanging provider cannot make
 * a cycle run past it — and when it is spent, the half it stopped in is recorded as failed and its
 * window stays where it was.
 *
 * For each case that is news, the recorder is called **before** its hash is stored. That order is
 * load-bearing: a recorder that answers {@see RecorderOutcome::NotFound} — the payment has not been
 * observed yet — must leave the hash unstored, or the next poll would find the case unchanged,
 * emit nothing, and the dispute would be gone.
 */
final class DisputePoller
{
    /** Cases by when they were raised or last updated. The reference's first endpoint. */
    private const string PATH_UPDATED = '/api/Chargeback/GetByUser';

    /** Cases by when they were decided. The reference's second endpoint, and not optional. */
    private const string PATH_RESOLVED = '/api/Chargeback/GetByResolvedDate';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    public function __construct(
        private readonly ConnexPayDisputesClientInterface $client,
        private readonly TransactionIdResolver $resolver,
        private readonly GatewayDisputeRecorder $recorder,
        private readonly GatewayId $gatewayId,
        private readonly ConnexPaySettings $settings,
        private readonly PollPolicy $policy = new PollPolicy,
        ?Closure $clock = null,
        ?Closure $sleeper = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable;
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * One cycle: read both cursors, report what is news, and hand back the cursor to store.
     *
     * Never throws for a provider failure — see {@see PollFailure} for why the failures are values
     * on the result and what the caller is expected to do with them.
     */
    public function poll(PollWindow $window): PollResult
    {
        $startedAt = $this->now();
        $differ = new CaseDiffer(
            ProviderSnapshotCanonicaliser::v1(),
            $this->settings->acquiringCurrency(),
            $window->hashes,
        );
        $tally = new PollTally;

        $updated = $this->read(self::PATH_UPDATED, $window->cases, $startedAt, $tally);
        if ($updated !== null && ! $this->observeAll(self::PATH_UPDATED, $updated, $differ, $startedAt, $tally)) {
            // The budget ran out part-way through this half: the cases after the stop were never
            // examined, so its window must not move past them.
            $updated = null;
        }

        $resolved = $this->read(self::PATH_RESOLVED, $window->resolved, $startedAt, $tally);
        if ($resolved !== null && ! $this->observeAll(self::PATH_RESOLVED, $resolved, $differ, $startedAt, $tally)) {
            $resolved = null;
        }

        $at = $this->now();
        $completed = $updated !== null && $resolved !== null;

        return new PollResult(
            window: new PollWindow(
                cases: $updated === null ? $window->cases : $window->cases->advancedTo($at, $this->policy->overlap()),
                resolved: $resolved === null ? $window->resolved : $window->resolved->advancedTo($at, $this->policy->overlap()),
                lastSuccessfulPoll: $completed ? $at : $window->lastSuccessfulPoll,
                hashes: $differ->known(),
            ),
            completed: $completed,
            observed: $tally->observed,
            emitted: $tally->emitted,
            unmatched: $tally->unmatched,
            failures: $tally->failures,
            unmappedCaseTypes: $tally->unmappedCaseTypes,
        );
    }

    /**
     * Reads one cursor, retrying with exponential backoff, and records why it gave up.
     *
     * Null means "this half of the cycle did not happen", and the caller treats it that way: the
     * window does not advance. The failure itself is on the tally, so the caller of `poll()` sees
     * it without this method having to throw and lose the other half's work.
     *
     * @return list<array<string, mixed>>|null
     */
    private function read(string $endpoint, DateRange $range, DateTimeImmutable $startedAt, PollTally $tally): ?array
    {
        $attempts = 0;
        $reason = '';

        while ($attempts < $this->policy->attempts) {
            $attempts++;

            // Checked before every attempt and not only at the front of the cycle: three attempts
            // against a host that answers slowly enough would otherwise run past the budget, and
            // the budget is the only bound a cycle has.
            if ($this->outOfBudget($startedAt)) {
                $tally->failures[] = new PollFailure($endpoint, null, sprintf(
                    'the cycle budget of %d second(s) was spent before this endpoint could be read '
                    .'(%d attempt(s) made, last failure: %s). Its window is not advanced, so the '
                    .'cases in it are read again next cycle.',
                    $this->policy->maxCycleSeconds,
                    $attempts - 1,
                    $reason === '' ? 'none' : $reason,
                ), $attempts);

                return null;
            }

            try {
                return $this->client->get($endpoint, $range->query());
            } catch (Throwable $failure) {
                // Any failure of the read is a failed read, and the message is kept for the
                // result. Nothing here inspects the exception: the alternatives are a cycle that
                // reports a window it never read, or a poller that dies on a 503.
                $reason = $failure->getMessage();
            }

            if ($attempts < $this->policy->attempts) {
                ($this->sleeper)($this->policy->backoffMilliseconds($attempts));
            }
        }

        $tally->failures[] = new PollFailure($endpoint, null, sprintf(
            'the read failed. Its window is not advanced, so nothing in it is lost: %s',
            $reason,
        ), $attempts);

        return null;
    }

    /**
     * Hands every case in one response to {@see self::observe()}, stopping if the budget is spent.
     *
     * Returns whether the whole list was examined. False means the budget stopped it, and the
     * caller must not advance this half's window.
     *
     * @param list<array<string, mixed>> $payloads
     */
    private function observeAll(
        string $endpoint,
        array $payloads,
        CaseDiffer $differ,
        DateTimeImmutable $startedAt,
        PollTally $tally,
    ): bool {
        foreach ($payloads as $payload) {
            if ($this->outOfBudget($startedAt)) {
                $tally->failures[] = new PollFailure($endpoint, null, sprintf(
                    'the cycle budget of %d second(s) was spent part-way through this response. The '
                    .'cases already reported are reported; the rest are read again next cycle, '
                    .'because this window is not advanced.',
                    $this->policy->maxCycleSeconds,
                ));

                return false;
            }

            $this->observe($endpoint, $payload, $differ, $tally);
        }

        return true;
    }

    /**
     * One case: refuse it, ignore it, or report it — and store its hash only once it has landed.
     *
     * @param array<string, mixed> $payload
     */
    private function observe(string $endpoint, array $payload, CaseDiffer $differ, PollTally $tally): void
    {
        $tally->observed++;

        try {
            $case = CaseSnapshot::fromPayload($payload);
        } catch (UnrepresentableCase $refusal) {
            // Refused by name, reported and not stored: the case is offered again on every poll
            // whose window still covers it, and never recorded with a value we invented.
            $tally->failures[] = new PollFailure($endpoint, self::caseNumberOf($payload), $refusal->getMessage());

            return;
        }

        if ($differ->changed($case) === null) {
            return;
        }

        if (CaseMapping::stage($case->caseType) === null) {
            // No stage is invented for a code the table does not cover — the raw CaseType travels
            // on the snapshot and the case is named here so an operator can look at it.
            $tally->unmappedCaseTypes[] = [
                'caseNumber' => $case->caseNumber,
                'caseType' => $case->caseType,
            ];
        }

        try {
            $outcome = $this->report($case, $tally);
        } catch (Throwable $failure) {
            $tally->failures[] = new PollFailure($endpoint, $case->caseNumber, sprintf(
                'the case could not be reported, so it is offered again next cycle: %s',
                $failure->getMessage(),
            ));

            return;
        }

        $tally->emitted++;

        if ($outcome === RecorderOutcome::NotFound) {
            // The recorder has not seen the payment this case names. Storing the hash now would
            // make the next poll find the case unchanged and never offer it again — which is how a
            // dispute is silently lost, and the reason this check is here rather than in the
            // recorder.
            $tally->failures[] = new PollFailure($endpoint, $case->caseNumber, sprintf(
                'the recorder has not observed the payment this case names yet (%s); the case is '
                .'offered again next cycle.',
                $outcome->name,
            ), 1);

            return;
        }

        $differ->accept($case);
    }

    /**
     * Hands one case to the recorder, on the call its matching allows.
     *
     * Matched cases go through `onDisputeObserved()` with the whole snapshot — including a case
     * that is already decided, whose derived status can be applied by an aggregate being opened
     * for the first time. Cases matching nothing go through `onUnmatchedDispute()` with everything
     * the payload carried, including the three references an operator needs to search for them.
     */
    private function report(CaseSnapshot $case, PollTally $tally): RecorderOutcome
    {
        $observedAt = $this->now();
        $hash = ProviderSnapshotCanonicaliser::v1()->canonicalise(
            $case->hashFields($this->settings->acquiringCurrency()),
        );
        $paymentIntentId = $this->match($case);

        if ($paymentIntentId !== null) {
            return $this->recorder->onDisputeObserved($this->gatewayId, $paymentIntentId, new DisputeSnapshot(
                gatewayDisputeRef: $case->caseNumber,
                cardBrand: $case->cardBrand,
                reasonCode: $case->reasonCode,
                // Non-empty by construction: it is the canonical hash of the case's own state, and
                // a constant or absent key would make every later delivery of this case look
                // identical to the first.
                providerEventKey: $hash,
                observedAt: $observedAt,
                // Raw, both of them: the cycle position for the stage history — a second
                // chargeback is CaseType 2 in the same family — and the code the status was read
                // from, so a correction to CaseMapping needs no change here.
                stageCode: $case->caseType === '' ? null : $case->caseType,
                statusCode: CaseMapping::status($case->resolutionTo, $case->winLoss),
                outcomeCode: $case->winLoss,
                waitingOnCode: $case->resolutionTo,
                hasResponse: $case->hasResponse,
                disputedAmount: $this->money($case->amount),
                responseDueAt: $case->dueDate,
                familyRef: $case->familyRef(),
                // Empty, and not for want of trying: no CMS case field states a fee. The $20 the
                // reference lists is ConnexPay's own charge on the settlement, which A4 reads from
                // settlement data rather than from a case payload.
                fees: [],
            ));
        }

        $tally->unmatched++;

        return $this->recorder->onUnmatchedDispute($this->gatewayId, new UnmatchedDispute(
            gatewayDisputeRef: $case->caseNumber,
            cardBrand: $case->cardBrand,
            reasonCode: $case->reasonCode,
            providerEventKey: $hash,
            observedAt: $observedAt,
            familyRef: $case->familyRef(),
            orderNumber: $case->orderNumber,
            arn: $case->arn,
            saleGuid: $case->saleGuid,
            stageCode: $case->caseType === '' ? null : $case->caseType,
            statusCode: CaseMapping::status($case->resolutionTo, $case->winLoss),
            disputedAmount: $this->money($case->amount),
            responseDueAt: $case->dueDate,
        ));
    }

    /**
     * The payment this case belongs to, by `SaleGuid` first, then `OrderNumber`, then `Arn`.
     *
     * All three go through the same {@see TransactionIdResolver}, which matches
     * a `gateway_references` row's `reference` **exactly** — it recognises a value we stored for
     * the payment and never our own aggregate id. So the question for each key is not "is this the
     * payment?" but "does that row hold this string right now?", and the three answers differ.
     *
     * **`SaleGuid` primary.** It is ConnexPay's own guid for the sale the case is against, and it
     * is the value the row holds for this payment once the sale is confirmed — for a card-data
     * sale, whose guid the sales API returns synchronously and the port stores there, and for a
     * hosted-page sale, whose guid arrives on the sale webhook and takes the row over from the
     * `OrderNumber` it had stored until then. The two paths are spelled out in
     * {@see \Techork\PaymentService\ConnexPay\Webhook\SaleCorrelation}. It is the only one of the
     * three with a row behind it in the ordinary case, which is why it is tried first rather than
     * last: a first chargeback on a card-data sale is the ordinary case.
     *
     * **`OrderNumber` second.** It is the value we sent ConnexPay as `clientUniqueId`, which for
     * this service is the PaymentIntent id itself — the only reference on the case that is ours
     * outright, and the only one we know we sent. Being ours does not make it resolvable, though:
     * it goes through the same exact match as the other two, so it answers only while the payment's
     * row still holds it, and that window closes when the sale is confirmed and the same row is
     * overwritten with the sale guid. By the time a case exists that overwrite has already
     * happened, because a chargeback is raised against a settled payment and settling is what
     * replaced the value. It is kept because it is the one key guaranteed to be on a case we sent,
     * and because it would still resolve if the guid ever went missing from a payload.
     *
     * **`Arn` last, and not a working fallback.** Nothing in this repository stores an ARN
     * anywhere — the string appears only in this package's dispute code. This branch therefore
     * resolves only if an application outside this tree stored an ARN alongside the payment as one
     * of its gateway references; in a deployment that did not, it is a fallback in name only, and
     * every case it alone could identify lands on the unmatched queue rather than being resolved
     * wrongly. It costs one indexed lookup, so it stays.
     *
     * ## The overwrite on transition, and the one thing still open
     *
     * The Laravel-side
     * {@see \Techork\PaymentService\Laravel\Repository\EloquentGatewayTransactionRepository} writes
     * `reference` on every transition — replace, not merge — so a capture does replace the value
     * the row holds. That is what the primary key rests on rather than a hazard to it, and it is
     * worth saying why: a capture answers with the **settled sale's** guid and not the capture's,
     * which is why {@see \Techork\PaymentService\ConnexPay\Capture} unwraps the response's nested
     * `sale` and why `MapsConnexPayOutcome::outcome()` records the `guid` of whatever body it is
     * handed. After it the row holds the settled transaction's guid — and a chargeback is raised
     * against the settled transaction, so that is the guid the case names. The opening reference is
     * not left homeless either: `metadata` is merged and never overwritten, and
     * {@see \Techork\PaymentService\ConnexPay\Concern\MapsConnexPayOutcome::openingMetadata()}
     * already keeps it at `opening_transaction_reference` for the operations that open a payment.
     *
     * **Still open, and narrower than it first looks:** whether the guid a capture returns for an
     * **auth-only** payment is the same string a case names as `SaleGuid` — such a payment's
     * `reference` moves from the auth guid to the sale guid on capture — and how a
     * `PartialCapture` response relates to that. A card-data sale takes no separate capture, so for
     * the ordinary case the question does not arise at all. This is the owner's call to make, and a
     * live check is what answers it; nothing here should be read as settled until it is made.
     */
    private function match(CaseSnapshot $case): ?string
    {
        foreach ([$case->saleGuid, $case->orderNumber, $case->arn] as $reference) {
            if ($reference === null || trim($reference) === '') {
                continue;
            }

            $paymentIntentId = $this->resolver->resolvePaymentIntent($this->gatewayId, $reference);

            if ($paymentIntentId !== null) {
                return $paymentIntentId;
            }
        }

        return null;
    }

    /**
     * The disputed amount, in the account's own currency.
     *
     * Converted through the same reader the hash uses, so the figure the aggregate books and the
     * figure the key was taken over cannot drift: the CMS returns a bare decimal with no currency
     * anywhere in the payload, and what the number is denominated in is a fact of the account
     * being polled. `Amount` and not `NetPosition` — ConnexPay states the first as the case's
     * amount (positive) and the second as the running balance of debits and credits (negative
     * while the case is open), and F1's aggregate books a positive disputed amount.
     */
    private function money(int|float|string|null $amount): ?Money
    {
        $currency = $this->settings->acquiringCurrency();
        $minorUnits = ProviderSnapshotCanonicaliser::amount($amount, $currency);

        if ($minorUnits === ProviderSnapshotCanonicaliser::ABSENT) {
            return null;
        }

        return new Money((int) $minorUnits, new Currency($currency));
    }

    private function outOfBudget(DateTimeImmutable $startedAt): bool
    {
        $now = $this->now();

        return ((float) $now->format('U.u') - (float) $startedAt->format('U.u')) >= $this->policy->maxCycleSeconds;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    /**
     * The case number of a payload that could not be turned into a snapshot.
     *
     * Read straight off the array, because the refusal is precisely that the snapshot could not be
     * built — and a failure that names no case is a failure an operator cannot look up.
     *
     * @param array<string, mixed> $payload
     */
    private static function caseNumberOf(array $payload): ?string
    {
        $caseNumber = $payload['CaseNumber'] ?? $payload['caseNumber'] ?? null;

        return is_scalar($caseNumber) ? (string) $caseNumber : null;
    }
}
