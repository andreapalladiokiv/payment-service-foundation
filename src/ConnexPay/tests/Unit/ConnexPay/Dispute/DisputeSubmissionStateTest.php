<?php

declare(strict_types=1);

use Money\Money;
use Techork\PaymentService\ConnexPay\ConnexPayDisputesClientInterface;
use Techork\PaymentService\ConnexPay\Dispute\CaseMapping;
use Techork\PaymentService\ConnexPay\Dispute\DisputePoller;
use Techork\PaymentService\ConnexPay\Dispute\PollWindow;
use Techork\PaymentService\Domain\Dispute\Command\ConfirmEvidenceUploadCommand;
use Techork\PaymentService\Domain\Dispute\Command\OpenDisputeCommand;
use Techork\PaymentService\Domain\Dispute\Command\SubmitDisputeEvidenceCommand;
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeResolution;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\UnmatchedDispute;

/**
 * F9's state test: **a poll that reports no response does not move the submission axis.**
 *
 * ## The mistake this file exists to prevent
 *
 * ConnexPay has no submission API. The response is filed by an operator in ConnexPay's own portal,
 * and `HasResponse` is the only thing that ever comes back about it — a flag that flips to `true`
 * when the CMS sees a response and, on a later poll, can read `false` again for reasons the payload
 * does not explain (a re-issued case, a portal record the CMS has not picked up, a case whose
 * resolution moved on). If a `false` were read as "the response is gone", the aggregate would be
 * demoted from `SUBMITTED` back to `DRAFT`, and the case would then look unanswered while the
 * response window kept running — the case lost by default, which is the one outcome the whole
 * dispute domain exists to prevent. So `confirmEvidenceUpload(confirmed: false)` records nothing and
 * moves nothing, and this file drives that through F5's own poller rather than around it.
 *
 * ## What is real here and what is stood in for
 *
 * Real: F5's `DisputePoller`, F5's `CaseMapping` for the status code, F1's `DisputeAggregate`, and
 * the payload — CB-1004 out of `tests/Fixtures/Disputes/`, the case the plan names for this
 * (`ResolutionTo: M`, `WinLoss: "Loss (Pending)"`, `HasResponse: false`).
 *
 * Stood in for: **the application.** The recorder at the bottom of this file is the smallest form of
 * the ConnexPay applier — open the aggregate on first sight, then hand the provider's own
 * acknowledgement to `confirmEvidenceUpload()`. The real applier (A0/A3) does more (deadline, stage
 * history, fees, family grouping); none of that is what this test is about, and the whole of the
 * acknowledgement path is here.
 *
 * ## Why a provider suite reaches into the domain, and why that is allowed
 *
 * The state this test asserts is F1's `SubmissionState`, and no provider package may name a
 * `Domain\Dispute\*` type — `tests/Arch/PackageHierarchyTest.php` enforces that over `src/`, and
 * test files are outside it. The precedent is
 * `src/Stripe/tests/Unit/Stripe/Webhook/DisputeIngestionContractTest.php`, which reaches across for
 * the same reason: an end-to-end statement about a provider's cycle cannot be made below the
 * boundary. Nothing in `src/ConnexPay/src/` learns anything from this file.
 *
 * ## No HTTP
 *
 * `ConnexPayDisputesClientInterface` is faked below and every payload is hand-written from
 * ConnexPay's documented field list. No test here — or anywhere in this package — calls ConnexPay.
 *
 * Helpers are prefixed `cmsState…`: Pest helpers are global to the whole suite.
 */

/**
 * The transport: cases by endpoint, mutable between polls, so a "later poll" is one field different.
 */
final class CmsStateClient implements ConnexPayDisputesClientInterface
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param  list<array<string, mixed>>  $updated  what `GetByUser` answers
     * @param  list<array<string, mixed>>  $resolved  what `GetByResolvedDate` answers
     */
    public function __construct(
        public array $updated = [],
        public array $resolved = [],
    ) {}

    public function get(string $path, array $query): array
    {
        $this->calls[] = $path;

        return $path === '/api/Chargeback/GetByUser' ? $this->updated : $this->resolved;
    }

    /**
     * The same case, one field different — what a re-poll of a CMS that has moved on looks like.
     *
     * @param  array<string, mixed>  $changes
     */
    public function change(string $caseNumber, array $changes): void
    {
        foreach ($this->updated as $index => $case) {
            if (($case['CaseNumber'] ?? null) === $caseNumber) {
                $this->updated[$index] = [...$case, ...$changes];
            }
        }
    }

    /** The case as it now stands. */
    public function case(string $caseNumber): array
    {
        foreach ($this->updated as $case) {
            if (($case['CaseNumber'] ?? null) === $caseNumber) {
                return $case;
            }
        }

        throw new RuntimeException("no case {$caseNumber} in this client");
    }
}

/**
 * The application, stood in for — see the file docblock. It is a recorder and not just a spy: the
 * aggregate's two axes are only reachable from here, and the acknowledgement's handling is F9's
 * substance, so it is exercised rather than asserted from the outside.
 */
final class CmsStateRecorder implements GatewayDisputeRecorder
{
    public ?DisputeAggregate $dispute = null;

    /** @var list<DisputeSnapshot> */
    public array $observed = [];

    public function onDisputeObserved(GatewayId $gatewayId, string $paymentIntentId, DisputeSnapshot $snapshot): RecorderOutcome
    {
        $this->observed[] = $snapshot;

        // Opening is the applier's decision (a case can already exist for this reference) and not
        // something the poller does: F5 hands over the snapshot and the payment it matched, and the
        // reverse lookup lives in the application.
        $this->dispute ??= DisputeAggregate::open(cmsStateOpening($snapshot, $paymentIntentId));

        // The provider's own word, and nothing added to it: `HasResponse` is what the aggregate is
        // told, and a `false` is the absence of a new fact rather than a fact.
        $this->dispute->confirmEvidenceUpload(cmsStateAcknowledgement($this->dispute->aggregateRootId(), $snapshot));

        return RecorderOutcome::Applied;
    }

    public function onDisputeResolved(GatewayId $gatewayId, string $disputeRef, DisputeResolution $resolution): RecorderOutcome
    {
        // ConnexPay never sends one: a CMS read always returns the whole case, so a resolution
        // arrives through the observed path with more of the case attached to it.
        throw new RuntimeException('the ConnexPay poller does not call onDisputeResolved()');
    }

    public function onUnmatchedDispute(GatewayId $gatewayId, UnmatchedDispute $case): RecorderOutcome
    {
        // This case matched its payment; a test that wanted the unmatched path is F5's.
        throw new RuntimeException('this case is matched, so nothing should reach the unmatched queue');
    }

    /** The state the aggregate is in, for the assertions below. */
    public function submissionState(): SubmissionState
    {
        return $this->dispute?->submissionState() ?? throw new RuntimeException('nothing has been observed');
    }
}

/**
 * The reference resolver: the fixture's `OrderNumber` is our own reference for the payment, and what
 * it resolves to is our PaymentIntent's id.
 *
 * The indirection is the point rather than ceremony — the case carries `pi-0004` and the aggregate
 * carries a uuid, and a resolver that answered the reference it was asked about would be a resolver
 * that never resolved anything.
 *
 * It answers whichever non-blank reference it is handed, which is deliberate here: the poller asks
 * about the case's sale guid before its `OrderNumber`, and this file is about what happens to a case
 * once a payment has been found for it, not about which key found it. Matching order is pinned in
 * DisputePollerTest.
 */
final class CmsStateResolver implements TransactionIdResolver
{
    public const string PAYMENT_INTENT = '01961f5a-0000-7000-8000-0000000000f9';

    public function resolvePaymentIntent(GatewayId $gatewayId, string $reference): ?string
    {
        return trim($reference) === '' ? null : self::PAYMENT_INTENT;
    }

    public function resolveRefund(GatewayId $gatewayId, string $reference): ?string
    {
        return null;
    }
}

function cmsStateAt(string $at = '2026-09-14T10:00:00Z'): DateTimeImmutable
{
    return new DateTimeImmutable($at);
}

/** The window every cycle starts from, and the one the next cycle advances to. */
function cmsStateWindow(): PollWindow
{
    return PollWindow::covering(
        new DateTimeImmutable('2026-09-01T00:00:00Z'),
        new DateTimeImmutable('2026-09-14T00:00:00Z'),
    );
}

/** CB-1004, the case the plan names for this: raised against us, undecided, no response yet. */
function cmsStateCase(): array
{
    return [
        'FamilyId' => 'FAM-1004',
        'CaseNumber' => 'CB-1004',
        'CaseType' => 1,
        'ReasonCode' => '10.4',
        'CardBrand' => 1,
        'ResolutionTo' => 'M',
        'Amount' => 60.0,
        'DueDate' => '2026-09-24T00:00:00',
        'OrderNumber' => 'pi-0004',
        'Arn' => 'ARN-0004',
        'NetPosition' => -60.0,
        'WinLoss' => 'Loss (Pending)',
        'HasResponse' => false,
        'HasImage' => false,
        'IsSurrendered' => false,
        'SaleGuid' => 'sale-0004',
        'AuthCode' => 'AUTH04',
        'ResolvedDate' => null,
        'ImageFileUrl' => null,
    ];
}

function cmsStatePoller(CmsStateClient $client, CmsStateRecorder $recorder): DisputePoller
{
    return new DisputePoller(
        client: $client,
        resolver: new CmsStateResolver,
        recorder: $recorder,
        gatewayId: GatewayId::generate(),
        settings: cpSettings(),
        clock: static fn (): DateTimeImmutable => cmsStateAt(),
        sleeper: static function (int $milliseconds): void {},
    );
}

/**
 * The application's translation of one snapshot onto an opening — see the file docblock. The stage
 * and the status come from F5's own tables, which is what keeps this from being a second reading of
 * the provider's codes.
 *
 * The provider's reference is not part of it. An opening no longer carries the provider's name for
 * the case — the aggregate is named by our own `DisputeId` and nothing else — so the `CaseNumber` on
 * the snapshot travels to `gateway_references` instead, which is the application's write and not this
 * test's subject; the poller's own suite is where the matching that produces it is pinned.
 */
function cmsStateOpening(DisputeSnapshot $snapshot, string $paymentIntentId): OpenDisputeCommand
{
    $stageCode = CaseMapping::stage($snapshot->stageCode);
    $statusCode = $snapshot->statusCode;

    return new class(
        DisputeId::generate(),
        PaymentIntentId::fromString($paymentIntentId),
        $stageCode === null ? DisputeStage::Chargeback : DisputeStage::from($stageCode),
        $statusCode === null ? DisputeStatus::NeedsResponse : DisputeStatus::from($statusCode),
        DisputeReason::fromProviderCode($snapshot->cardBrand, $snapshot->reasonCode),
        $snapshot->disputedAmount ?? throw new RuntimeException('a dispute with no amount cannot be opened'),
        $snapshot->responseDueAt,
        $snapshot->stageCode,
        new DisputeSignal($snapshot->providerEventKey, $snapshot->observedAt),
    ) implements OpenDisputeCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly PaymentIntentId $paymentIntent,
            private readonly DisputeStage $stage,
            private readonly DisputeStatus $status,
            private readonly DisputeReason $reason,
            private readonly Money $amount,
            private readonly ?DateTimeImmutable $deadline,
            private readonly ?string $code,
            private readonly DisputeSignal $signal,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function paymentIntentId(): PaymentIntentId
        {
            return $this->paymentIntent;
        }

        public function stage(): DisputeStage
        {
            return $this->stage;
        }

        public function status(): DisputeStatus
        {
            return $this->status;
        }

        public function reason(): DisputeReason
        {
            return $this->reason;
        }

        public function disputedAmount(): Money
        {
            return $this->amount;
        }

        public function deadlineAt(): ?DateTimeImmutable
        {
            return $this->deadline;
        }

        public function providerCode(): ?string
        {
            return $this->code;
        }

        public function signal(): DisputeSignal
        {
            return $this->signal;
        }
    };
}

/**
 * What the provider said about our response, handed on unchanged. `confirmed` is `HasResponse` and
 * nothing else: there is no third answer, and no way to infer one from a case that has moved on.
 */
function cmsStateAcknowledgement(DisputeId $id, DisputeSnapshot $snapshot): ConfirmEvidenceUploadCommand
{
    return new class(
        $id,
        $snapshot->hasResponse === true,
        new DisputeSignal($snapshot->providerEventKey, $snapshot->observedAt),
    ) implements ConfirmEvidenceUploadCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly bool $confirmed,
            private readonly DisputeSignal $delivery,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function confirmed(): bool
        {
            return $this->confirmed;
        }

        public function signal(): DisputeSignal
        {
            return $this->delivery;
        }
    };
}

/** The operator's filing, as the application records it: the types the response was composed of. */
function cmsStateSubmission(DisputeId $id, DateTimeImmutable $at): SubmitDisputeEvidenceCommand
{
    return new class($id, $at) implements SubmitDisputeEvidenceCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly DateTimeImmutable $at,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        /** @return list<EvidenceType> */
        public function types(): array
        {
            // The project's own document is what F9's composition puts first among what the portal
            // is asked for; *what* the operator actually filed in the portal is not knowable from
            // here, and this test is about the submission axis rather than its contents.
            return [EvidenceType::ProofOfDeliveryOrService];
        }

        public function at(): DateTimeImmutable
        {
            return $this->at;
        }
    };
}

// ──────────────────────────────────────────────
//  the state the whole task turns on
// ──────────────────────────────────────────────

/**
 * The test F9 is done by. Everything before the middle assertion is the setup for it; everything
 * after is what stops it passing for the wrong reason.
 */
it('leaves SUBMITTED where it is when a later poll reports no response', function () {
    $client = new CmsStateClient(updated: [cmsStateCase()]);
    $recorder = new CmsStateRecorder;
    $poller = cmsStatePoller($client, $recorder);

    // ── the first cycle: the case arrives, and nobody has answered it ──
    $first = $poller->poll(cmsStateWindow());

    expect($first->failures)->toBe([])
        ->and($first->completed)->toBeTrue()
        ->and($recorder->observed)->toHaveCount(1)
        ->and($recorder->observed[0]->hasResponse)->toBeFalse()
        ->and($recorder->submissionState())->toBe(SubmissionState::None)
        ->and($recorder->dispute?->status())->toBe(DisputeStatus::NeedsResponse);

    // ── the operator files the response in ConnexPay's portal, and we record that they did ──
    // This is the whole of what ConnexPay can tell us: the operator's word. It is the reason
    // `SUBMITTED` at this provider is a claim rather than a fact.
    $recorder->dispute?->submitEvidence(cmsStateSubmission(
        $recorder->dispute->aggregateRootId(),
        cmsStateAt('2026-09-15T09:00:00Z'),
    ));

    expect($recorder->submissionState())->toBe(SubmissionState::Submitted);

    // ── the next cycle: the case has moved (the balance moved), and still says no response ──
    $client->change('CB-1004', ['NetPosition' => -50.0]);

    $second = $poller->poll($first->window);

    expect($second->failures)->toBe([])
        ->and($second->completed)->toBeTrue()
        // The case really was re-reported — so what follows is a statement about the poll that
        // happened and not about a poll that failed.
        ->and($recorder->observed)->toHaveCount(2)
        ->and($recorder->observed[1]->hasResponse)->toBeFalse()
        // THE ASSERTION: `HasResponse: false` on a later poll must not demote the submission.
        ->and($recorder->submissionState())->toBe(SubmissionState::Submitted)
        // And nothing was acknowledged, either: a `false` is the absence of a new fact, so there is
        // no timestamp to write and no event to record.
        ->and($recorder->dispute?->submissionConfirmedAt())->toBeNull()
        ->and($recorder->dispute?->evidenceUploadConfirmedAt())->toBeNull()
        // The other axis is where it was, and that is load-bearing for F9 rather than incidental:
        // the case is still waiting on us, which is what keeps the operator's portal link on the
        // screen while the submitted response is unconfirmed — and takes the mistake back to the
        // one place it can be discovered.
        ->and($recorder->dispute?->status())->toBe(DisputeStatus::NeedsResponse);
});

/**
 * The other half of the same rule, and the reason the assertion above is not a statement that
 * nothing ever moves: the provider *can* confirm, and when it does the state advances. Without this
 * a `confirmEvidenceUpload()` that did nothing at all would pass the test above.
 */
it('advances to CONFIRMED when the provider reports the response itself', function () {
    $client = new CmsStateClient(updated: [cmsStateCase()]);
    $recorder = new CmsStateRecorder;
    $poller = cmsStatePoller($client, $recorder);

    $first = $poller->poll(cmsStateWindow());

    $recorder->dispute?->submitEvidence(cmsStateSubmission(
        $recorder->dispute->aggregateRootId(),
        cmsStateAt('2026-09-15T09:00:00Z'),
    ));

    $client->change('CB-1004', ['NetPosition' => -50.0, 'HasResponse' => true]);

    $poller->poll($first->window);

    expect($recorder->observed)->toHaveCount(2)
        ->and($recorder->observed[1]->hasResponse)->toBeTrue()
        ->and($recorder->submissionState())->toBe(SubmissionState::Confirmed)
        ->and($recorder->dispute?->submissionConfirmedAt())->not->toBeNull()
        // `CONFIRMED` is written by the acknowledgement and by nothing else — not by a stage move,
        // not by a status move, not by the passage of time.
        ->and($recorder->dispute?->evidenceUploadConfirmedAt())->toBeNull();
});

/**
 * A case that is re-reported with nothing about the response changed emits nothing at all: the
 * hashes are the same, so the poller never reaches the recorder, and no acknowledgement is handed
 * over for the aggregate to ignore. That is the ordinary quiet cycle, and it is what makes the
 * second cycle above a real one rather than a poll that never noticed a change.
 */
it('does not re-report a case whose state has not changed', function () {
    $client = new CmsStateClient(updated: [cmsStateCase()]);
    $recorder = new CmsStateRecorder;
    $poller = cmsStatePoller($client, $recorder);

    $first = $poller->poll(cmsStateWindow());
    $second = $poller->poll($first->window);

    expect($second->completed)->toBeTrue()
        ->and($second->observed)->toBe(1)
        ->and($second->emitted)->toBe(0)
        // The recorder saw the case once, and it is the *only* thing the poller knows about: the
        // aggregate's state is never read back by the poll, which is why no poll can demote it.
        ->and($recorder->observed)->toHaveCount(1);
});
