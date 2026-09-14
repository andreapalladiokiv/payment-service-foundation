<?php

declare(strict_types=1);

use Techork\PaymentService\ConnexPay\Dispute\CaseDiffer;
use Techork\PaymentService\ConnexPay\Dispute\CaseSnapshot;
use Techork\PaymentService\ConnexPay\Dispute\ProviderSnapshotCanonicaliser;

/**
 * The comparison half of the idempotency contract: one hash per case, compared against the most
 * recent one, never against a set of every hash ever seen.
 *
 * The payload here is the documented case from
 * `tests/Fixtures/Disputes/get-by-user.doc-sample.json` — a documentation example of mine, not a
 * payload recorded from ConnexPay — rendered inline because each test states a *variant* of it.
 *
 * Helper is prefixed `cmsDifferCase`; Pest helpers are global for the whole suite.
 */

/**
 * @param  array<string, mixed>  $overrides
 */
function cmsDifferCase(array $overrides = []): CaseSnapshot
{
    return CaseSnapshot::fromPayload($overrides + [
        'FamilyId' => 'FAM-1001',
        'CaseNumber' => 'CB-1001',
        'CaseType' => 1,
        'ReasonCode' => '13.1',
        'CardBrand' => 1,
        'ResolutionTo' => 'M',
        'Amount' => 120.00,
        'DueDate' => '2026-09-20T00:00:00',
        'OrderNumber' => 'pi-0001',
        'Arn' => 'ARN-0001',
        'NetPosition' => -120.00,
        'WinLoss' => null,
        'HasResponse' => false,
        'HasImage' => false,
        'IsSurrendered' => false,
    ]);
}

function cmsDiffer(): CaseDiffer
{
    return new CaseDiffer(ProviderSnapshotCanonicaliser::v1(), 'USD');
}

it('treats a case it has never held a hash for as news', function () {
    // Which is how a backlog import reports every case in its window exactly once.
    expect(cmsDiffer()->changed(cmsDifferCase()))->not->toBeNull();
});

it('emits nothing for the same case stated the same way a second time', function () {
    $differ = cmsDiffer();
    $case = cmsDifferCase();

    $differ->accept($case);

    expect($differ->changed($case))->toBeNull();
});

it('seeds itself with the hashes the caller stored next to the cursor', function () {
    $first = cmsDifferCase();
    $stored = ProviderSnapshotCanonicaliser::v1()->canonicalise($first->hashFields('USD'));
    $differ = new CaseDiffer(ProviderSnapshotCanonicaliser::v1(), 'USD', ['CB-1001' => $stored]);

    expect($differ->changed($first))->toBeNull();
});

it('reports a case that returns to a state it held before', function () {
    // The scenario F1 names and the reason a set of hashes ever seen is wrong:
    //
    //   first chargeback   -> hash A  -> reported
    //   we respond         -> hash B  -> reported (HasResponse flipped)
    //   the bank rejects us-> hash A  -> reported again  <- a set would suppress this
    //
    // A case legitimately returns to a combination of values it held before, and that return is
    // exactly what the HasResponse and WinLoss mappings exist to report.
    $differ = cmsDiffer();

    $first = cmsDifferCase();
    $differ->accept($first);

    $responded = cmsDifferCase(['HasResponse' => true]);
    expect($differ->changed($responded))->not->toBeNull();
    $differ->accept($responded);

    expect($differ->changed($first))->not->toBeNull();
});

it('reports a case that moved on any single field of the contract', function (array $overrides) {
    $differ = cmsDiffer();
    $differ->accept(cmsDifferCase());

    expect($differ->changed(cmsDifferCase($overrides)))->not->toBeNull();
})->with([
    'FamilyId' => [['FamilyId' => 'FAM-2002']],
    'CaseType' => [['CaseType' => 2]],
    'ReasonCode' => [['ReasonCode' => '13.2']],
    'ResolutionTo' => [['ResolutionTo' => 'B']],
    'WinLoss' => [['WinLoss' => 'Loss']],
    'HasResponse' => [['HasResponse' => true]],
    'HasImage' => [['HasImage' => true]],
    'IsSurrendered' => [['IsSurrendered' => true]],
    'DueDate' => [['DueDate' => '2026-09-21T00:00:00']],
    'NetPosition' => [['NetPosition' => -60.00]],
]);

it('emits nothing for a field the contract does not hash', function (array $overrides) {
    // The field list is the contract, and it is eleven fields long. A payload field outside it
    // cannot move the key: if it could, every unrelated CMS field change would be reported as a
    // dispute event, and the aggregate would accumulate events that state nothing.
    $differ = cmsDiffer();
    $differ->accept(cmsDifferCase());

    expect($differ->changed(cmsDifferCase($overrides)))->toBeNull();
})->with([
    'Amount' => [['Amount' => 999.00]],
    'OrderNumber' => [['OrderNumber' => 'pi-other']],
    'Arn' => [['Arn' => 'ARN-other']],
]);

it('hashes the same case the same way on both endpoints, whatever the payload\'s spelling', function () {
    // The whole point of the canonicaliser: `GetByUser` and `GetByResolvedDate` return the same
    // case in differently shaped payloads, and a case that hashed differently on the second
    // endpoint would look like a new dispute every time the resolution cursor caught up.
    $user = cmsDifferCase();

    $resolved = CaseSnapshot::fromPayload([
        'caseNumber' => 'CB-1001',
        'familyId' => 'FAM-1001',
        'caseType' => '1',
        'reasonCode' => '13.1',
        'cardBrand' => '1',
        'resolutionTo' => 'M',
        'winLoss' => null,
        'hasResponse' => 'false',
        'hasImage' => 0,
        'isSurrendered' => false,
        'dueDate' => '2026-09-20T00:00:00',
        'netPosition' => -120,
    ]);

    expect(cmsDiffer()->hash($resolved))->toBe(cmsDiffer()->hash($user));
});

it('hands back the caller\'s hashes with this cycle\'s merges in, and prunes nothing', function () {
    // Pruning is the caller's: a case that leaves the window and comes back — a second chargeback
    // on the same family — has to be compared against its last known state, and an entry dropped
    // for being out of the window would present it as brand new.
    $differ = new CaseDiffer(ProviderSnapshotCanonicaliser::v1(), 'USD', ['CB-OLD' => 'v1:stored']);
    $differ->accept(cmsDifferCase());

    expect($differ->known())->toHaveKeys(['CB-OLD', 'CB-1001'])
        ->and($differ->known()['CB-OLD'])->toBe('v1:stored');
});

it('recomputes the hash on accept rather than trusting anything passed in', function () {
    $case = cmsDifferCase();
    $differ = cmsDiffer();
    $differ->accept($case);

    // The stored value is the canonicaliser's own answer for the case, so there is no route by
    // which a caller can store a hash belonging to a case it does not name.
    expect($differ->known()['CB-1001'])->toBe(ProviderSnapshotCanonicaliser::v1()->canonicalise($case->hashFields('USD')));
});
