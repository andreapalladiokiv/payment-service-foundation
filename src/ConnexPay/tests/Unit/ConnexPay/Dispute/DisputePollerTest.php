<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\ConnexPayDisputesClientInterface;
use Techork\PaymentService\ConnexPay\Dispute\DateRange;
use Techork\PaymentService\ConnexPay\Dispute\DisputePoller;
use Techork\PaymentService\ConnexPay\Dispute\PollPolicy;
use Techork\PaymentService\ConnexPay\Dispute\PollWindow;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeResolution;
use Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\UnmatchedDispute;

/**
 * The poll cycle, end to end, against payloads that never left this file.
 *
 * ## The fixtures are documentation examples of mine
 *
 * Everything under `tests/Fixtures/Disputes/` was written by hand from ConnexPay's documented
 * field list and its own sample response, and every file is suffixed `.doc-sample.json` to say so.
 * **None of it was recorded from ConnexPay.** F0 replaces them in place with real captured
 * payloads; until then these are the shape of a case as the documentation describes it, and they
 * are the only payloads any test here ever sees.
 *
 * ## No test here makes a call
 *
 * `ConnexPayDisputesClientInterface` is a one-method seam, so the fake below answers in place of
 * the transport and records what it was asked for. Nothing in this file — or anywhere else in this
 * package's tests — reaches ConnexPay, and the CMS credentials are an open question owned by a
 * human: the four `CONNEXPAY_SANDBOX_*` variables in this repository are the sales-API pair, and
 * the CMS Basic-auth pair is created separately from both it and the CRM user.
 *
 * Helpers and fakes are prefixed `cmsPoll…`; Pest helpers are global for the whole suite.
 */

/**
 * A fixture, by name, decoded. The `.doc-sample.json` suffix is on every file here.
 *
 * @return list<array<string, mixed>>
 */
function cmsPollFixture(string $name): array
{
    $path = dirname(__DIR__, 3).'/Fixtures/Disputes/'.$name.'.doc-sample.json';

    /** @var list<array<string, mixed>> $cases */
    $cases = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $cases;
}

/** The transport, with a scripted failure count per endpoint. */
final class CmsPollClient implements ConnexPayDisputesClientInterface
{
    /** @var list<array{path: string, query: array<string, string>}> */
    public array $calls = [];

    /**
     * @param  array<string, list<array<string, mixed>>>  $payloads  path => the cases it answers
     * @param  array<string, int>  $failures  path => how many reads fail before one succeeds
     */
    public function __construct(
        private array $payloads = [],
        private array $failures = [],
    ) {}

    public function get(string $path, array $query): array
    {
        $this->calls[] = ['path' => $path, 'query' => $query];

        if (($this->failures[$path] ?? 0) > 0) {
            $this->failures[$path]--;

            throw new RuntimeException('the CMS read failed (scripted)');
        }

        return $this->payloads[$path] ?? [];
    }

    /** @return list<string> the paths read, in order */
    public function paths(): array
    {
        return array_map(static fn (array $call): string => $call['path'], $this->calls);
    }

    /** The query the nth call for a path carried, or null. */
    public function queryFor(string $path, int $occurrence = 0): ?array
    {
        $seen = 0;

        foreach ($this->calls as $call) {
            if ($call['path'] === $path && $seen++ === $occurrence) {
                return $call['query'];
            }
        }

        return null;
    }
}

/** The recorder, keeping every call it was handed. */
final class CmsPollRecorder implements GatewayDisputeRecorder
{
    /** @var list<array{gatewayId: GatewayId, paymentIntentId: string, snapshot: DisputeSnapshot}> */
    public array $observed = [];

    /** @var list<array{gatewayId: GatewayId, disputeRef: string, resolution: DisputeResolution}> */
    public array $resolutions = [];

    /** @var list<UnmatchedDispute> */
    public array $unmatched = [];

    /** What every call answers. `NotFound` is how "the payment is not here yet" is said. */
    public RecorderOutcome $answer = RecorderOutcome::Applied;

    public function onDisputeObserved(GatewayId $gatewayId, string $paymentIntentId, DisputeSnapshot $snapshot): RecorderOutcome
    {
        $this->observed[] = [
            'gatewayId' => $gatewayId,
            'paymentIntentId' => $paymentIntentId,
            'snapshot' => $snapshot,
        ];

        return $this->answer;
    }

    public function onDisputeResolved(GatewayId $gatewayId, string $disputeRef, DisputeResolution $resolution): RecorderOutcome
    {
        $this->resolutions[] = [
            'gatewayId' => $gatewayId,
            'disputeRef' => $disputeRef,
            'resolution' => $resolution,
        ];

        return $this->answer;
    }

    public function onUnmatchedDispute(GatewayId $gatewayId, UnmatchedDispute $case): RecorderOutcome
    {
        $this->unmatched[] = $case;

        return $this->answer;
    }

    /** The case numbers observed, in order. */
    public function refs(): array
    {
        return array_map(static fn (array $call): string => $call['snapshot']->gatewayDisputeRef, $this->observed);
    }
}

/** The reference resolver, over a fixed map of gateway reference => our payment intent id. */
final class CmsPollResolver implements TransactionIdResolver
{
    /** @var list<string> every reference it was asked about, in order */
    public array $asked = [];

    /** @param  array<string, string>  $map */
    public function __construct(private array $map = []) {}

    public function resolvePaymentIntent(GatewayId $gatewayId, string $reference): ?string
    {
        $this->asked[] = $reference;

        return $this->map[$reference] ?? null;
    }

    public function resolveRefund(GatewayId $gatewayId, string $reference): ?string
    {
        return null;
    }
}

/** The window every test starts from: a fortnight of September, and nothing known yet. */
function cmsPollWindow(): PollWindow
{
    return PollWindow::covering(
        new DateTimeImmutable('2026-09-01T00:00:00Z'),
        new DateTimeImmutable('2026-09-14T00:00:00Z'),
    );
}

/**
 * A poller wired for a test: a clock that stands still unless a test moves it, and a sleeper that
 * records the backoff it was asked for instead of passing the time.
 *
 * @param  Closure(): DateTimeImmutable|null  $clock
 * @param  Closure(int): void|null  $sleeper
 */
function cmsPollPoller(
    CmsPollClient $client,
    CmsPollResolver $resolver,
    CmsPollRecorder $recorder,
    ?PollPolicy $policy = null,
    ?Closure $clock = null,
    ?Closure $sleeper = null,
): DisputePoller {
    return new DisputePoller(
        client: $client,
        resolver: $resolver,
        recorder: $recorder,
        gatewayId: GatewayId::generate(),
        settings: cpSettings(),
        policy: $policy ?? new PollPolicy,
        clock: $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-14T10:00:00Z'),
        sleeper: $sleeper ?? static function (int $milliseconds): void {},
    );
}

/** A resolver that knows every fixture case by its `OrderNumber`, which is our own reference. */
function cmsPollResolvedByName(array $cases): CmsPollResolver
{
    $map = [];

    foreach ($cases as $case) {
        $map[(string) $case['OrderNumber']] = 'pi-intent-'.$case['OrderNumber'];
    }

    return new CmsPollResolver($map);
}

/** A client answering both cursors from the two documented fixtures. */
function cmsPollDocumentedClient(array $failures = []): CmsPollClient
{
    return new CmsPollClient([
        '/api/Chargeback/GetByUser' => cmsPollFixture('get-by-user'),
        '/api/Chargeback/GetByResolvedDate' => cmsPollFixture('get-by-resolved'),
    ], $failures);
}

it('reads both cursors every cycle, with the same date window', function () {
    // Both, and neither is optional: a case raised six weeks ago can resolve today, and a poller
    // that only reads the first cursor leaves disputes open on our side forever while ConnexPay has
    // already closed them.
    $client = cmsPollDocumentedClient();

    cmsPollPoller($client, new CmsPollResolver, new CmsPollRecorder)->poll(cmsPollWindow());

    expect($client->paths())->toBe([
        '/api/Chargeback/GetByUser',
        '/api/Chargeback/GetByResolvedDate',
    ])->and($client->queryFor('/api/Chargeback/GetByUser'))->toBe([
        'startDate' => '2026-09-01',
        'endDate' => '2026-09-14',
    ])->and($client->queryFor('/api/Chargeback/GetByResolvedDate'))->toBe([
        'startDate' => '2026-09-01',
        'endDate' => '2026-09-14',
    ]);
});

it('reports a case it matched by OrderNumber, with the whole snapshot', function () {
    $client = cmsPollDocumentedClient();
    $resolver = cmsPollResolvedByName([...cmsPollFixture('get-by-user'), ...cmsPollFixture('get-by-resolved')]);
    $recorder = new CmsPollRecorder;

    cmsPollPoller($client, $resolver, $recorder)->poll(cmsPollWindow());

    $first = $recorder->observed[0];

    expect($first['paymentIntentId'])->toBe('pi-intent-pi-0001')
        ->and($first['snapshot']->gatewayDisputeRef)->toBe('CB-1001')
        ->and($first['snapshot']->cardBrand)->toBe(CardBrand::Visa)
        ->and($first['snapshot']->reasonCode)->toBe('13.1')
        // The raw CaseType, because the stage history needs the cycle position and a second
        // chargeback is CaseType 2 inside the same family.
        ->and($first['snapshot']->stageCode)->toBe('1')
        ->and($first['snapshot']->outcomeCode)->toBe('Loss')
        ->and($first['snapshot']->waitingOnCode)->toBe('B')
        ->and($first['snapshot']->statusCode)->toBe('lost')
        ->and($first['snapshot']->hasResponse)->toBeTrue()
        ->and($first['snapshot']->disputedAmount?->getAmount())->toBe('12000')
        ->and($first['snapshot']->disputedAmount?->getCurrency()->getCode())->toBe('USD')
        ->and($first['snapshot']->responseDueAt?->format('Y-m-d'))->toBe('2026-09-20')
        ->and($first['snapshot']->familyRef)->toBe('FAM-1001')
        // No CMS case field states a fee: ConnexPay's own $20 is charged on the settlement, and A4
        // reads it from settlement data.
        ->and($first['snapshot']->fees)->toBe([]);
});

it('carries a provider event key that is never empty and changes with the case', function () {
    // A constant or absent key would make every later delivery of a case look identical to the
    // first, and the aggregate would drop every change.
    $client = cmsPollDocumentedClient();
    $resolver = cmsPollResolvedByName([...cmsPollFixture('get-by-user'), ...cmsPollFixture('get-by-resolved')]);
    $recorder = new CmsPollRecorder;

    cmsPollPoller($client, $resolver, $recorder)->poll(cmsPollWindow());

    $keys = array_map(static fn (array $call): string => $call['snapshot']->providerEventKey, $recorder->observed);

    expect($keys)->not->toContain('')
        ->and(array_unique($keys))->toHaveCount(count($keys))
        // Derived from the canonicaliser's own hash of the case — not from a counter, a timestamp
        // or a random value, all of which would make the same state report as news every poll.
        ->and($keys[0])->toStartWith('v1:');
});

it('reads the status the case is standing in off the fields the provider states', function (string $caseNumber, ?string $status) {
    $client = cmsPollDocumentedClient();
    $resolver = cmsPollResolvedByName([...cmsPollFixture('get-by-user'), ...cmsPollFixture('get-by-resolved')]);
    $recorder = new CmsPollRecorder;

    cmsPollPoller($client, $resolver, $recorder)->poll(cmsPollWindow());

    $found = null;

    foreach ($recorder->observed as $call) {
        if ($call['snapshot']->gatewayDisputeRef === $caseNumber) {
            $found = $call['snapshot']->statusCode;
        }
    }

    expect($found)->toBe($status);
})->with([
    // `Loss (Pending)` is NOT lost: ConnexPay defines it as a case whose status is "Not Worked",
    // so it is still ours to answer. This is our reading — see CaseMapping::status().
    'a pending loss waiting on us' => ['CB-1004', 'needs_response'],
    // A retrieval request the bank has: not ours to answer yet.
    'an amex retrieval with the bank' => ['CB-1002', 'under_review'],
    // A case Type 25, which the stage table does not cover, still gets the status its fields state.
    'an unmapped case type still has a status' => ['CB-1003', 'needs_response'],
    'a decided case' => ['CB-1001', 'lost'],
    // The headline the second cursor exists for: a case decided before we ever read it.
    'a case that resolved before we ever saw it' => ['CB-2001', 'won'],
]);

it('walks the case\'s references in order and stops at the first one that answers', function () {
    $client = new CmsPollClient(['/api/Chargeback/GetByUser' => cmsPollFixture('arn-fallback')]);
    // The guid on this case resolves nothing here and the OrderNumber is empty, so the ARN is the
    // only key left. The ARN is the acquirer's own reference for the transaction, shared by every
    // case of it — the right fallback and the wrong primary, because it is not a reference we ever
    // sent and can only resolve if the application stored it.
    $resolver = new CmsPollResolver(['ARN-0001' => 'pi-intent-from-arn']);
    $recorder = new CmsPollRecorder;

    $result = cmsPollPoller($client, $resolver, $recorder)->poll(cmsPollWindow());

    expect($recorder->observed)->toHaveCount(1)
        ->and($recorder->observed[0]['paymentIntentId'])->toBe('pi-intent-from-arn')
        ->and($recorder->observed[0]['snapshot']->gatewayDisputeRef)->toBe('CB-1101')
        // Asked in the order the poller states, with the blank one skipped rather than offered: the
        // guid, then (nothing for the empty OrderNumber), then the ARN — and no fourth question,
        // because the third answered.
        ->and($resolver->asked)->toBe(['sale-0001', 'ARN-0001'])
        ->and($recorder->unmatched)->toBe([])
        ->and($result->unmatched)->toBe(0);
});

it('routes a family\'s second chargeback by FamilyId, with a new reference of its own', function () {
    // ConnexPay's second chargeback carries a NEW CaseNumber inside the SAME FamilyId. The
    // reference is what the recorder keys the individual event on, and the family is what lets it
    // find the aggregate the first chargeback opened rather than opening a second one — which is
    // the difference between one dispute with two cycles and two disputes.
    $client = new CmsPollClient(['/api/Chargeback/GetByUser' => cmsPollFixture('arn-fallback')]);
    $recorder = new CmsPollRecorder;

    cmsPollPoller($client, new CmsPollResolver(['ARN-0001' => 'pi-intent-from-arn']), $recorder)
        ->poll(cmsPollWindow());

    $snapshot = $recorder->observed[0]['snapshot'];

    expect($snapshot->familyRef)->toBe('FAM-1001')
        ->and($snapshot->gatewayDisputeRef)->toBe('CB-1101')
        // The cycle position rides raw, which is how the aggregate's stage history tells the second
        // chargeback from the first inside that one family.
        ->and($snapshot->stageCode)->toBe('2')
        // A second chargeback waiting on us with no WinLoss yet: the fields state no decision, so
        // the position is the only thing that answers it.
        ->and($snapshot->statusCode)->toBe('needs_response');
});

it('surfaces a case that matches nothing instead of dropping it or retrying it forever', function () {
    $client = new CmsPollClient(['/api/Chargeback/GetByUser' => cmsPollFixture('unmatched')]);
    $recorder = new CmsPollRecorder;

    $result = cmsPollPoller($client, new CmsPollResolver, $recorder)->poll(cmsPollWindow());

    expect($recorder->observed)->toBe([])
        ->and($recorder->unmatched)->toHaveCount(1)
        // Everything we know travels, including the three references an operator has to search by.
        ->and($recorder->unmatched[0]->gatewayDisputeRef)->toBe('CB-9001')
        ->and($recorder->unmatched[0]->saleGuid)->toBe('sale-9001')
        ->and($recorder->unmatched[0]->orderNumber)->toBe('pi-foreign')
        ->and($recorder->unmatched[0]->arn)->toBe('ARN-FOREIGN')
        ->and($recorder->unmatched[0]->cardBrand)->toBe(CardBrand::Discover)
        ->and($recorder->unmatched[0]->reasonCode)->toBe('C08')
        ->and($recorder->unmatched[0]->stageCode)->toBe('1')
        ->and($recorder->unmatched[0]->statusCode)->toBe('needs_response')
        ->and($recorder->unmatched[0]->disputedAmount?->getAmount())->toBe('7777')
        ->and($recorder->unmatched[0]->providerEventKey)->toStartWith('v1:')
        ->and($result->unmatched)->toBe(1)
        ->and($result->emitted)->toBe(1);
});

it('reports an unmatched case once and not on the next poll', function () {
    // Its hash is stored like any other case's, which is what stops an operator being handed the
    // same unknown case on every cycle until someone deals with it.
    $client = new CmsPollClient(['/api/Chargeback/GetByUser' => cmsPollFixture('unmatched')]);
    $recorder = new CmsPollRecorder;
    $poller = cmsPollPoller($client, new CmsPollResolver, $recorder);

    $first = $poller->poll(cmsPollWindow());
    $second = $poller->poll($first->window);

    expect($recorder->unmatched)->toHaveCount(1)
        ->and($second->emitted)->toBe(0)
        ->and($second->observed)->toBe(1)
        ->and($second->unmatched)->toBe(0);
});

it('emits nothing when the same payload comes back a second time', function () {
    // The diff. Re-running one cycle with exactly the payloads it already reported must produce no
    // recorder call at all — which is the only thing standing between a poll every minute and an
    // aggregate that receives the same dispute sixty times an hour.
    $client = cmsPollDocumentedClient();
    $resolver = cmsPollResolvedByName([...cmsPollFixture('get-by-user'), ...cmsPollFixture('get-by-resolved')]);
    $recorder = new CmsPollRecorder;
    $poller = cmsPollPoller($client, $resolver, $recorder);

    $first = $poller->poll(cmsPollWindow());
    $afterFirst = count($recorder->observed) + count($recorder->unmatched);

    $second = $poller->poll($first->window);

    expect($afterFirst)->toBe(5)
        // The payloads were all read again...
        ->and($second->observed)->toBe(6)
        // ...and none of them was news.
        ->and($second->emitted)->toBe(0)
        ->and(count($recorder->observed) + count($recorder->unmatched))->toBe($afterFirst);
});

it('reports the same case once when both cursors return it in one cycle', function () {
    // GetByUser and GetByResolvedDate overlap on purpose — a resolved case is also an updated one —
    // and the duplicate is free: the second statement of an unchanged case hashes the same and the
    // differ absorbs it.
    $case = cmsPollFixture('get-by-resolved')[0];
    $client = new CmsPollClient([
        '/api/Chargeback/GetByUser' => [$case],
        '/api/Chargeback/GetByResolvedDate' => [$case],
    ]);
    $recorder = new CmsPollRecorder;

    $result = cmsPollPoller($client, new CmsPollResolver(['pi-0001' => 'pi-intent-1']), $recorder)
        ->poll(cmsPollWindow());

    expect($recorder->observed)->toHaveCount(1)
        ->and($result->observed)->toBe(2)
        ->and($result->emitted)->toBe(1);
});

it('hands the new hashes back for the caller to store next to the cursor', function () {
    // The poller holds no state between cycles: the cursor *and* the hashes travel back on the
    // result, and the application stores both.
    $client = cmsPollDocumentedClient();
    $resolver = cmsPollResolvedByName([...cmsPollFixture('get-by-user'), ...cmsPollFixture('get-by-resolved')]);

    $result = cmsPollPoller($client, $resolver, new CmsPollRecorder)->poll(cmsPollWindow());

    expect($result->hashes())->toHaveKeys(['CB-1001', 'CB-1002', 'CB-1003', 'CB-1004', 'CB-2001'])
        ->and($result->hashes()['CB-1001'])->toStartWith('v1:')
        ->and($result->window->hashes)->toBe($result->hashes());
});

it('does not store the hash of a case whose payment the recorder has not seen yet', function () {
    // The one ordering that matters: the recorder is called BEFORE the hash is stored. Storing it
    // first would make the next poll find the case unchanged, emit nothing, and lose the dispute.
    $client = new CmsPollClient(['/api/Chargeback/GetByUser' => cmsPollFixture('unmatched')]);
    $recorder = new CmsPollRecorder;
    $recorder->answer = RecorderOutcome::NotFound;
    $poller = cmsPollPoller($client, new CmsPollResolver, $recorder);

    $first = $poller->poll(cmsPollWindow());

    expect($first->hashes())->toBe([])
        ->and($first->failures)->toHaveCount(1)
        ->and($first->failures[0]->caseNumber)->toBe('CB-9001');

    $second = $poller->poll($first->window);

    // Offered again, which is the whole point: the payment may have landed by now.
    expect($recorder->unmatched)->toHaveCount(2)
        ->and($second->emitted)->toBe(1);
});

it('advances both windows only as far as the clock, keeping a day of overlap', function () {
    // The CMS takes dates, not timestamps: a poll at 10:00 cannot ask for "today from 10:00", so
    // without the overlap a case updated at 11:00 could fall between two windows.
    $client = cmsPollDocumentedClient();

    $result = cmsPollPoller($client, new CmsPollResolver, new CmsPollRecorder)->poll(cmsPollWindow());

    expect($result->window->cases->query())->toBe(['startDate' => '2026-09-13', 'endDate' => '2026-09-14'])
        ->and($result->window->resolved->query())->toBe(['startDate' => '2026-09-13', 'endDate' => '2026-09-14'])
        ->and($result->completed)->toBeTrue()
        ->and($result->lastSuccessfulPoll()?->format('Y-m-d H:i:s'))->toBe('2026-09-14 10:00:00');
});

it('never advances a window past a half of the cycle it did not read', function () {
    // A failed GetByResolvedDate that advanced its window would skip those days entirely, and the
    // cases resolved in them would never be seen again — the same failure as not reading the cursor
    // at all.
    $client = cmsPollDocumentedClient(failures: ['/api/Chargeback/GetByResolvedDate' => 3]);

    $result = cmsPollPoller($client, new CmsPollResolver, new CmsPollRecorder)->poll(cmsPollWindow());

    expect($result->completed)->toBeFalse()
        ->and($result->window->cases->query())->toBe(['startDate' => '2026-09-13', 'endDate' => '2026-09-14'])
        ->and($result->window->resolved->query())->toBe(['startDate' => '2026-09-01', 'endDate' => '2026-09-14'])
        // The staleness alarm reads this, so a half-read cycle must not look healthy.
        ->and($result->lastSuccessfulPoll())->toBeNull()
        ->and($result->failures)->toHaveCount(1)
        ->and($result->failures[0]->endpoint)->toBe('/api/Chargeback/GetByResolvedDate')
        ->and($result->failures[0]->attempts)->toBe(3);
});

it('retries a read with exponential backoff and reports the cycle as complete when it succeeds', function () {
    $slept = [];
    $client = cmsPollDocumentedClient(failures: ['/api/Chargeback/GetByUser' => 2]);

    $result = cmsPollPoller(
        $client,
        cmsPollResolvedByName([...cmsPollFixture('get-by-user'), ...cmsPollFixture('get-by-resolved')]),
        new CmsPollRecorder,
        policy: new PollPolicy(attempts: 3, baseBackoffMilliseconds: 250),
        sleeper: static function (int $milliseconds) use (&$slept): void {
            $slept[] = $milliseconds;
        },
    )->poll(cmsPollWindow());

    // Two failures, so two waits: the base, then twice it. No jitter — a poll is not a thundering
    // herd, and a deterministic schedule is one an operator can recognise in a log.
    expect($slept)->toBe([250, 500])
        ->and($result->completed)->toBeTrue()
        ->and($result->failures)->toBe([])
        ->and($result->emitted)->toBe(5);
});

it('gives up after the configured number of attempts and says what the last failure was', function () {
    $client = cmsPollDocumentedClient(failures: [
        '/api/Chargeback/GetByUser' => 3,
        '/api/Chargeback/GetByResolvedDate' => 3,
    ]);

    $result = cmsPollPoller($client, new CmsPollResolver, new CmsPollRecorder)->poll(cmsPollWindow());

    expect($result->failures)->toHaveCount(2)
        ->and($result->failures[0]->reason)->toContain('the CMS read failed (scripted)')
        ->and($result->completed)->toBeFalse()
        // Three attempts each, and no fourth: the retry budget is what the policy says it is.
        ->and($client->paths())->toHaveCount(6)
        ->and($result->window->cases->query())->toBe(['startDate' => '2026-09-01', 'endDate' => '2026-09-14']);
});

it('caps the cycle and stops, rather than running past its budget', function () {
    // The budget is the only thing bounding a cycle: Guzzle bounds a request and the CMS bounds
    // nothing. A cycle that stops must not advance the window it stopped in.
    $now = new DateTimeImmutable('2026-09-14T10:00:00Z');
    $slept = [];

    $result = cmsPollPoller(
        cmsPollDocumentedClient(failures: [
            '/api/Chargeback/GetByUser' => 9,
            '/api/Chargeback/GetByResolvedDate' => 9,
        ]),
        new CmsPollResolver,
        new CmsPollRecorder,
        policy: new PollPolicy(attempts: 3, baseBackoffMilliseconds: 250, maxCycleSeconds: 60),
        clock: static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        // Each wait costs half a minute of wall clock, so the second attempt is already out of
        // budget before it is made.
        sleeper: static function (int $milliseconds) use (&$now, &$slept): void {
            $slept[] = $milliseconds;
            $now = $now->modify('+30 seconds');
        },
    )->poll(cmsPollWindow());

    expect($result->completed)->toBeFalse()
        ->and($result->window->cases->query())->toBe(['startDate' => '2026-09-01', 'endDate' => '2026-09-14'])
        ->and($result->window->resolved->query())->toBe(['startDate' => '2026-09-01', 'endDate' => '2026-09-14'])
        ->and($result->lastSuccessfulPoll())->toBeNull()
        ->and($result->failures)->not->toBe([])
        ->and($result->failures[0]->reason)->toContain('cycle budget')
        // Two waits — half a minute each — and then the third attempt was never made, because the
        // budget was checked before it rather than after it.
        ->and($slept)->toBe([250, 500]);
});

it('keeps going when one case cannot be represented, and reports which one', function () {
    // A CardBrand of 5 is PayPal, which has no case in our card-brand vocabulary. The reference
    // says to refuse it by name rather than guess, and one unrepresentable case must not stop the
    // rest of the response.
    $good = cmsPollFixture('unmatched')[0];
    $client = new CmsPollClient(['/api/Chargeback/GetByUser' => [
        $good,
        ['FamilyId' => 'FAM-9002', 'CaseNumber' => 'CB-9002', 'CaseType' => 1, 'ReasonCode' => 'C08', 'CardBrand' => 5, 'ResolutionTo' => 'M', 'Amount' => 10.00],
        ['FamilyId' => 'FAM-9003', 'CaseNumber' => 'CB-9003', 'CaseType' => 1, 'ReasonCode' => 'C08', 'CardBrand' => 1, 'ResolutionTo' => 'M', 'Amount' => 11.00],
    ]]);
    $recorder = new CmsPollRecorder;

    $result = cmsPollPoller($client, new CmsPollResolver, $recorder)->poll(cmsPollWindow());

    expect($recorder->unmatched)->toHaveCount(2)
        ->and($result->observed)->toBe(3)
        ->and($result->failures)->toHaveCount(1)
        ->and($result->failures[0]->caseNumber)->toBe('CB-9002')
        ->and($result->failures[0]->reason)->toContain('5')
        // A refused case's hash is not stored either, so it is offered again — visibly — rather
        // than disappearing.
        ->and($result->hashes())->toHaveKeys(['CB-9001', 'CB-9003'])
        ->and($result->hashes())->not->toHaveKey('CB-9002');
});

it('names a CaseType the stage table does not cover on the result', function () {
    // The plan: any CaseType outside the table is "an unmapped-case error surfaced to the operator,
    // not a silent default". The case is still reported — its status and amount are real — and the
    // operator is told which code nobody has mapped yet.
    $client = cmsPollDocumentedClient();
    $resolver = cmsPollResolvedByName([...cmsPollFixture('get-by-user'), ...cmsPollFixture('get-by-resolved')]);

    $result = cmsPollPoller($client, $resolver, new CmsPollRecorder)->poll(cmsPollWindow());

    expect($result->unmappedCaseTypes)->toBe([
        ['caseNumber' => 'CB-1003', 'caseType' => '25'],
    ]);
});

it('names an unmapped CaseType once, not on every poll', function () {
    $client = cmsPollDocumentedClient();
    $resolver = cmsPollResolvedByName([...cmsPollFixture('get-by-user'), ...cmsPollFixture('get-by-resolved')]);
    $poller = cmsPollPoller($client, $resolver, new CmsPollRecorder);

    $first = $poller->poll(cmsPollWindow());
    $second = $poller->poll($first->window);

    expect($first->unmappedCaseTypes)->toHaveCount(1)
        ->and($second->unmappedCaseTypes)->toBe([]);
});

it('carries the reported counts back on the result', function () {
    $client = cmsPollDocumentedClient();
    $resolver = new CmsPollResolver(['pi-0001' => 'pi-intent-1']);
    $recorder = new CmsPollRecorder;

    $result = cmsPollPoller($client, $resolver, $recorder)->poll(cmsPollWindow());

    expect($result->observed)->toBe(6)
        // Five reports: CB-1001, CB-1002, CB-1003 and CB-1004 from the first cursor and CB-2001
        // from the second, with the second cursor's restatement of CB-1001 absorbed.
        ->and($result->emitted)->toBe(5)
        // Three of them could not be tied to a payment: everything but CB-1001.
        ->and($result->unmatched)->toBe(4)
        ->and($result->failures)->toBe([])
        ->and($result->completed)->toBeTrue();
});

it('never calls onDisputeResolved, because a CMS read always returns the whole case', function () {
    // That call exists for a resolution that arrives alone, addressed by the provider's own
    // reference. ConnexPay never sends one, and the same fact reaches the aggregate through the
    // observed path with more of the case attached.
    $client = cmsPollDocumentedClient();
    $resolver = cmsPollResolvedByName([...cmsPollFixture('get-by-user'), ...cmsPollFixture('get-by-resolved')]);
    $recorder = new CmsPollRecorder;

    cmsPollPoller($client, $resolver, $recorder)->poll(cmsPollWindow());

    expect($recorder->resolutions)->toBe([]);
});

it('matches a case on the sale guid alone, and never asks about a blank reference', function () {
    // The ordinary card-data chargeback: the case names the sale ConnexPay settled, that guid is
    // what the payment's reference row holds, and the OrderNumber is empty here — so the guid is
    // the only key that answers. It has to answer: without it the most ordinary case there is
    // would land on the operator's unmatched queue.
    $client = new CmsPollClient(['/api/Chargeback/GetByUser' => cmsPollFixture('arn-fallback')]);
    // The guid resolves; the blank OrderNumber is not offered to the resolver at all, so the ARN —
    // which nothing here stores — is never reached.
    $resolver = new CmsPollResolver(['sale-0001' => 'pi-intent-by-sale-guid']);
    $recorder = new CmsPollRecorder;

    $result = cmsPollPoller($client, $resolver, $recorder)->poll(cmsPollWindow());

    expect($recorder->observed)->toHaveCount(1)
        ->and($recorder->observed[0]['paymentIntentId'])->toBe('pi-intent-by-sale-guid')
        ->and($recorder->observed[0]['snapshot']->gatewayDisputeRef)->toBe('CB-1101')
        ->and($resolver->asked)->toBe(['sale-0001'])
        ->and($recorder->unmatched)->toBe([])
        ->and($result->unmatched)->toBe(0);
});

it('prefers the guid when every reference on the case resolves', function () {
    $case = cmsPollFixture('get-by-user')[0];
    $client = new CmsPollClient(['/api/Chargeback/GetByUser' => [$case]]);
    // All three are in the map, so each one *would* answer; which one the case is matched by is
    // therefore the poller's order alone, and not an accident of what happens to be resolvable.
    $resolver = new CmsPollResolver([
        'sale-0001' => 'pi-intent-by-sale-guid',
        'pi-0001' => 'pi-intent-by-order-number',
        'ARN-0001' => 'pi-intent-by-arn',
    ]);
    $recorder = new CmsPollRecorder;

    cmsPollPoller($client, $resolver, $recorder)->poll(cmsPollWindow());

    expect($recorder->observed[0]['paymentIntentId'])->toBe('pi-intent-by-sale-guid')
        // Asked once, about the guid: the two fallbacks are not tried when the primary answers.
        ->and($resolver->asked)->toBe(['sale-0001']);
});

it('resolves by the OrderNumber through the same exact match, while a row still holds it', function () {
    // The OrderNumber is the `clientUniqueId` we sent, which for this service is the PaymentIntent
    // id itself — ours outright, and the one reference on a case we know we sent. Being ours does
    // not make it resolvable, though: it is looked up the same way the guid is, by exact match
    // against a `gateway_references` row's `reference`, and it answers only while the payment's row
    // still holds that string. A hosted-page sale overwrites the row with the sale guid when the
    // sale webhook confirms it, so this window closes at confirmation; the key is kept because it
    // is guaranteed to be on a case we sent, and it is what still answers if the guid is not there.
    $case = cmsPollFixture('get-by-user')[0];
    // The guid the sale was settled under is not on this payload — the one shape in which the
    // second key is the only one with anything behind it.
    $case['SaleGuid'] = '';
    $client = new CmsPollClient(['/api/Chargeback/GetByUser' => [$case]]);
    $resolver = new CmsPollResolver(['pi-0001' => 'pi-intent-by-order-number']);
    $recorder = new CmsPollRecorder;

    cmsPollPoller($client, $resolver, $recorder)->poll(cmsPollWindow());

    expect($recorder->observed)->toHaveCount(1)
        ->and($recorder->observed[0]['paymentIntentId'])->toBe('pi-intent-by-order-number')
        ->and($recorder->observed[0]['snapshot']->gatewayDisputeRef)->toBe('CB-1001')
        ->and($resolver->asked)->toBe(['pi-0001'])
        ->and($recorder->unmatched)->toBe([]);
});

it('reports a case the resolved cursor names even when it was never new', function () {
    // The bug the second cursor exists to prevent: the case was raised outside any window we ever
    // read and decided before we saw it. It appears on the resolved cursor alone, and it must be
    // reported — as a case already decided, with a status the aggregate can open straight into.
    $client = new CmsPollClient([
        '/api/Chargeback/GetByUser' => [],
        '/api/Chargeback/GetByResolvedDate' => [cmsPollFixture('get-by-resolved')[1]],
    ]);
    $recorder = new CmsPollRecorder;

    $result = cmsPollPoller($client, new CmsPollResolver(['pi-2001' => 'pi-intent-2001']), $recorder)
        ->poll(cmsPollWindow());

    expect($recorder->observed)->toHaveCount(1)
        ->and($recorder->observed[0]['snapshot']->gatewayDisputeRef)->toBe('CB-2001')
        ->and($recorder->observed[0]['snapshot']->statusCode)->toBe('won')
        ->and($recorder->observed[0]['snapshot']->outcomeCode)->toBe('Win')
        ->and($result->emitted)->toBe(1);
});

it('accepts a window that is already narrower than the clock without widening it', function () {
    // The caller owns the cursor. A poll whose clock has not moved past the window's end must not
    // produce a window that goes backwards — the guard in DateRange::advancedTo().
    $window = new PollWindow(
        new DateRange(new DateTimeImmutable('2026-09-10T00:00:00Z'), new DateTimeImmutable('2026-09-20T00:00:00Z')),
        new DateRange(new DateTimeImmutable('2026-09-10T00:00:00Z'), new DateTimeImmutable('2026-09-20T00:00:00Z')),
    );
    $client = new CmsPollClient;

    $result = cmsPollPoller($client, new CmsPollResolver, new CmsPollRecorder)->poll($window);

    expect($result->window->cases->query())->toBe(['startDate' => '2026-09-19', 'endDate' => '2026-09-20'])
        ->and($result->completed)->toBeTrue();
});

it('starts from nothing: an empty first poll is a complete cycle with nothing in it', function () {
    $result = cmsPollPoller(new CmsPollClient, new CmsPollResolver, new CmsPollRecorder)->poll(cmsPollWindow());

    expect($result->observed)->toBe(0)
        ->and($result->emitted)->toBe(0)
        ->and($result->unmatched)->toBe(0)
        ->and($result->failures)->toBe([])
        ->and($result->hashes())->toBe([])
        ->and($result->completed)->toBeTrue()
        ->and($result->lastSuccessfulPoll())->not->toBeNull();
});
