<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\ConnexPayDisputesClientInterface;
use Techork\PaymentService\ConnexPay\Dispute\DisputeCaseRead;
use Techork\PaymentService\ConnexPay\Dispute\UnrepresentableCase;
use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * The CMS read F9 puts behind {@see ReadsDisputeCases}: one case, out of a window, in the terms the
 * layer above asks in.
 *
 * ## Why a window is read to answer about one case, and what the test has to pin
 *
 * ConnexPay's CMS API has two endpoints and both take a date range — there is no "get case X" — so
 * this class reads a window and picks the case out of it. Two of the assertions below are about
 * exactly that and nothing else: the window is the poller's own {@see \Techork\PaymentService\ConnexPay\Dispute\PollWindow}
 * over a year, asked `GetByUser` first and `GetByResolvedDate` only when the first window did not
 * contain the case.
 *
 * ## What is read into the reading
 *
 * `awaitingResponse` is `ResolutionTo = M` and nothing else (F5's settled table). A case reading
 * `B` is *not* awaiting us and is not reported as awaiting anybody else, and its brand and reason
 * code are still carried, because the pair is what the evidence requirements are keyed on and a
 * caller that cannot see the case's pair cannot surface it properly. `concedable` is false on every
 * case — ConnexPay has no close call, so no payload could make it true.
 *
 * ## What failure is, in this file
 *
 * Three ways the read can fail and three different refusals, none of which is folded into a reading
 * of "nothing is waiting on us":
 *
 * ```
 * the window did not contain the case    RuntimeException, naming the case and the window
 * the case is there and cannot be read   UnrepresentableCase, propagated from CaseSnapshot
 * the transport failed                   whatever the client threw, propagated untouched
 * ```
 *
 * ## No HTTP
 *
 * `ConnexPayDisputesClientInterface` is faked below, and the payloads are F5's own hand-written
 * documentation samples under `tests/Fixtures/Disputes/`. Nothing in this file reaches ConnexPay.
 */

/**
 * A fixture, by name, decoded. The `.doc-sample.json` suffix is on every file in that directory.
 *
 * Declared here rather than shared with {@see \DisputePollerTest}: Pest helpers are global to the
 * suite, so a shared name would be a redeclaration, and F5's file is settled.
 *
 * @return list<array<string, mixed>>
 */
function cmsReadFixture(string $name): array
{
    $path = dirname(__DIR__, 3).'/Fixtures/Disputes/'.$name.'.doc-sample.json';

    /** @var list<array<string, mixed>> $cases */
    $cases = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $cases;
}

/** The transport: what each endpoint answers, and every window it was asked about. */
final class CmsReadClient implements ConnexPayDisputesClientInterface
{
    /** @var list<array{path: string, query: array<string, string>}> */
    public array $calls = [];

    /**
     * @param  array<string, list<array<string, mixed>>>  $payloads  path => the cases it answers
     */
    public function __construct(
        private array $payloads = [],
        private ?Throwable $failure = null,
    ) {}

    public function get(string $path, array $query): array
    {
        $this->calls[] = ['path' => $path, 'query' => $query];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->payloads[$path] ?? [];
    }
}

function cmsReadAt(string $at = '2026-09-14T09:00:00+00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at);
}

/** The read, with the clock pinned: the window it asks about is part of what is asserted. */
function cmsRead(ConnexPayDisputesClientInterface $client, string $caseNumber): DisputeCaseRead
{
    return new DisputeCaseRead(
        $client,
        new DisputeCaseQuery(GatewayId::generate(), $caseNumber),
        static fn (): DateTimeImmutable => cmsReadAt(),
    );
}

// ──────────────────────────────────────────────
//  what the provider says, read into the terms the layer above asks in
// ──────────────────────────────────────────────

it('reports a case the merchant must answer as awaiting us, with its own pair and deadline', function () {
    $reading = cmsRead(new CmsReadClient([
        '/api/Chargeback/GetByUser' => cmsReadFixture('get-by-user'),
    ]), 'CB-1004')->read();

    expect($reading->awaitingResponse)->toBeTrue()
        // The pair is the provider's own, verbatim: the reason code is matched against the
        // requirements table exactly as ConnexPay writes it.
        ->and($reading->cardBrand)->toBe(CardBrand::Visa->value)
        ->and($reading->reasonCode)->toBe('10.4')
        // `DueDate` as the case stated it, which is the whole of the operator's window.
        ->and($reading->respondBy?->format(DATE_ATOM))->toBe('2026-09-24T00:00:00+00:00')
        // No call to close a case exists at this provider, so no case is concedable through us.
        ->and($reading->concedable)->toBeFalse();
});

/**
 * `B` is the bank's turn, and it is the answer that matters most in this file: read wrongly, an
 * operator is given a task on a case the bank is working, and read as "nothing" it would be the
 * other mistake. `S` and `G` are neither party's and are not asserted separately — they are the
 * same "not waiting on us" with no waiter at all.
 */
it('reports a case the bank is on as not waiting on us, and still carries its pair', function () {
    $reading = cmsRead(new CmsReadClient([
        '/api/Chargeback/GetByUser' => cmsReadFixture('get-by-user'),
    ]), 'CB-1002')->read();

    expect($reading->awaitingResponse)->toBeFalse()
        ->and($reading->concedable)->toBeFalse()
        ->and($reading->cardBrand)->toBe(CardBrand::Amex->value)
        ->and($reading->reasonCode)->toBe('A01');
});

// ──────────────────────────────────────────────
//  the window, and the second endpoint
// ──────────────────────────────────────────────

it('asks the update window over a year, and stops there when it finds the case', function () {
    $client = new CmsReadClient(['/api/Chargeback/GetByUser' => cmsReadFixture('get-by-user')]);

    cmsRead($client, 'CB-1004')->read();

    expect($client->calls)->toHaveCount(1)
        ->and($client->calls[0]['path'])->toBe('/api/Chargeback/GetByUser')
        // UTC day boundaries, from the poller's own DateRange: the same spelling the poll sends.
        ->and($client->calls[0]['query'])->toBe(['startDate' => '2025-09-14', 'endDate' => '2026-09-14']);
});

/**
 * A case raised outside the lookback and decided inside it appears in the resolved window alone —
 * and the read still answers about it rather than reporting it as absent. The second request is the
 * price of that, and it is paid only when the first window misses.
 */
it('falls back to the resolved window for a case the update window does not hold', function () {
    $client = new CmsReadClient([
        '/api/Chargeback/GetByUser' => cmsReadFixture('get-by-user'),
        '/api/Chargeback/GetByResolvedDate' => cmsReadFixture('get-by-resolved'),
    ]);

    $reading = cmsRead($client, 'CB-2001')->read();

    expect($client->calls)->toHaveCount(2)
        ->and($client->calls[1]['path'])->toBe('/api/Chargeback/GetByResolvedDate')
        ->and($client->calls[1]['query'])->toBe($client->calls[0]['query'])
        // Decided and won, so not ours — but read from the case itself rather than assumed from
        // its absence in the first window.
        ->and($reading->awaitingResponse)->toBeFalse()
        ->and($reading->reasonCode)->toBe('4853');
});

// ──────────────────────────────────────────────
//  the three failures, none of which is an answer
// ──────────────────────────────────────────────

/**
 * The one that would be reported as "nothing is waiting on us" if this class answered instead of
 * refusing — and the case that would then be lost by default.
 */
it('refuses when neither window contains the case, rather than reporting nothing open', function () {
    $client = new CmsReadClient(['/api/Chargeback/GetByUser' => cmsReadFixture('get-by-user')]);

    expect(fn () => cmsRead($client, 'CB-9999')->read())
        ->toThrow(RuntimeException::class, 'CB-9999');

    // Both windows were read before it gave up: a case can be in either, so "not found" is not a
    // statement until both have answered.
    expect($client->calls)->toHaveCount(2);
});

it('propagates the refusal of a case that cannot be represented', function () {
    $cases = cmsReadFixture('get-by-user');
    // 5 is PayPal, which has no counterpart in CardBrand — a refusal rather than a guess at the
    // network whose evidence rules would then be applied to the case.
    $cases[3]['CardBrand'] = 5;

    expect(fn () => cmsRead(new CmsReadClient(['/api/Chargeback/GetByUser' => $cases]), 'CB-1004')->read())
        ->toThrow(UnrepresentableCase::class);
});

it('propagates a failed read instead of answering for a case it never read', function () {
    $client = new CmsReadClient(failure: new RuntimeException('the CMS read failed (scripted)'));

    expect(fn () => cmsRead($client, 'CB-1004')->read())
        ->toThrow(RuntimeException::class, 'the CMS read failed (scripted)');
});

/**
 * `ResolutionTo = M` with no `DueDate`: the reading's own invariant refuses it, and that is the
 * honest outcome. A case the merchant must answer has a window — a response task with no date is
 * one no operator can be given — and the alternative, dropping to "nothing open", would report a
 * case about to be lost as one with nothing to do.
 */
it('refuses an awaiting case the payload gave no deadline for', function () {
    $cases = cmsReadFixture('get-by-user');
    $cases[3]['DueDate'] = null;

    expect(fn () => cmsRead(new CmsReadClient(['/api/Chargeback/GetByUser' => $cases]), 'CB-1004')->read())
        ->toThrow(InvalidArgumentException::class, 'deadline');
});
