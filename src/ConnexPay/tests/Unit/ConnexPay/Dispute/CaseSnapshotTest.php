<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\Dispute\CaseSnapshot;
use Techork\PaymentService\ConnexPay\Dispute\UnrepresentableCase;
use Techork\PaymentService\ConnexPay\Dispute\ProviderSnapshotCanonicaliser;

/**
 * The shape F5 reads a CMS case into, and the two refusals it is allowed to make.
 *
 * The payloads below are written out here rather than loaded from
 * `tests/Fixtures/Disputes/`, because what this file tests is *spelling* — the same documented
 * case rendered the ways the two endpoints are known to render it — and a fixture that had to
 * carry every variant would be a fixture of variations rather than of a case. The case itself is
 * the one in `tests/Fixtures/Disputes/get-by-user.doc-sample.json`, which is a documentation
 * example of mine and not a payload recorded from ConnexPay.
 *
 * Helpers are prefixed `cmsCase…`; Pest helpers are global for the whole suite.
 */

/**
 * One case, with the documented field names and values, overridable key by key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cmsCasePayload(array $overrides = []): array
{
    return $overrides + [
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
        'WinLoss' => 'Loss (Pending)',
        'HasResponse' => false,
        'HasImage' => false,
        'IsSurrendered' => false,
    ];
}

it('reads the documented fields into the snapshot', function () {
    $case = CaseSnapshot::fromPayload(cmsCasePayload());

    expect($case->familyId)->toBe('FAM-1001')
        ->and($case->caseNumber)->toBe('CB-1001')
        ->and($case->caseType)->toBe('1')
        ->and($case->reasonCode)->toBe('13.1')
        ->and($case->cardBrand)->toBe(CardBrand::Visa)
        ->and($case->resolutionTo)->toBe('M')
        ->and($case->winLoss)->toBe('Loss (Pending)')
        ->and($case->hasResponse)->toBeFalse()
        ->and($case->hasImage)->toBeFalse()
        ->and($case->isSurrendered)->toBeFalse()
        ->and($case->netPosition)->toBe(-120.00)
        ->and($case->amount)->toBe(120.00)
        ->and($case->dueDate?->format('Y-m-d'))->toBe('2026-09-20')
        ->and($case->orderNumber)->toBe('pi-0001')
        ->and($case->arn)->toBe('ARN-0001');
});

it('reads the camelCase spelling as well, because ConnexPay\'s payloads vary', function () {
    // A null `OrderNumber` from a spelling difference would route every case to the unmatched
    // queue, so the fallback is not cosmetic.
    $case = CaseSnapshot::fromPayload([
        'familyId' => 'FAM-2',
        'caseNumber' => 'CB-2',
        'caseType' => '17',
        'reasonCode' => 'A01',
        'cardBrand' => '4',
        'resolutionTo' => 'B',
        'amount' => '20.00',
        'dueDate' => '2026-09-25T00:00:00',
        'orderNumber' => 'pi-2',
        'arn' => 'ARN-2',
        'netPosition' => '-20.00',
        'winLoss' => null,
        'hasResponse' => 'true',
    ]);

    expect($case->familyId)->toBe('FAM-2')
        ->and($case->caseType)->toBe('17')
        ->and($case->cardBrand)->toBe(CardBrand::Amex)
        ->and($case->orderNumber)->toBe('pi-2')
        ->and($case->arn)->toBe('ARN-2')
        ->and($case->hasResponse)->toBeTrue()
        // Absent is not false: "the provider stated nothing" is a different answer, and the
        // recorder's tri-state exists for it.
        ->and($case->hasImage)->toBeNull()
        ->and($case->isSurrendered)->toBeNull();
});

it('normalises the spellings of a boolean so both endpoints hash the same', function (mixed $stated, ?bool $expected) {
    expect(CaseSnapshot::fromPayload(cmsCasePayload(['HasResponse' => $stated]))->hasResponse)->toBe($expected);
})->with([
    'a JSON true' => [true, true],
    'the string true' => ['true', true],
    'the number 1' => [1, true],
    'the string 1' => ['1', true],
    'a JSON false' => [false, false],
    'the string false' => ['false', false],
    'the number 0' => [0, false],
    'the string 0' => ['0', false],
    'an empty string, which is absence' => ['', null],
    'an absent field' => [null, null],
    'something else entirely' => ['yes', null],
]);

it('hashes the eleven fields in the contract\'s order, and nothing else', function () {
    $fields = CaseSnapshot::fromPayload(cmsCasePayload())->hashFields('USD');

    // The order is the contract, not a preference: F1 fixes it and preserves the caller's order,
    // so a reordering here changes every key this package will ever produce.
    expect(array_keys($fields))->toBe([
        'FamilyId', 'CaseNumber', 'CaseType', 'ReasonCode', 'ResolutionTo', 'WinLoss',
        'HasResponse', 'HasImage', 'IsSurrendered', 'DueDate', 'NetPosition',
    ]);
});

it('reduces the fields through the canonicaliser\'s own readers', function () {
    $fields = CaseSnapshot::fromPayload(cmsCasePayload([
        'DueDate' => '2026-09-20T23:45:00',
        'NetPosition' => '-120.00',
        'WinLoss' => null,
    ]))->hashFields('USD');

    expect($fields['DueDate'])->toBe('2026-09-20')
        // Minor units, not the decimal: the amount is the payload's figure and the currency is the
        // account's, and no float touches a cent.
        ->and($fields['NetPosition'])->toBe('-12000')
        ->and($fields['WinLoss'])->toBeNull();
});

it('refuses a case with no CaseNumber, no ReasonCode, an unreadable date or a brand it cannot map', function (array $overrides, string $expected) {
    expect(fn () => CaseSnapshot::fromPayload(cmsCasePayload($overrides)))
        ->toThrow(UnrepresentableCase::class, $expected);
})->with([
    'no CaseNumber' => [['CaseNumber' => null], 'CaseNumber'],
    'a blank CaseNumber' => [['CaseNumber' => '  '], 'CaseNumber'],
    'no ReasonCode' => [['ReasonCode' => null], 'ReasonCode'],
    'a blank ReasonCode' => [['ReasonCode' => ''], 'ReasonCode'],
    'CardBrand 5, PayPal' => [['CardBrand' => 5], '5'],
    // A case with no brand is refused rather than guessed: the brand is half of the (brand, code)
    // pair every evidence requirement is keyed on.
    'no CardBrand' => [['CardBrand' => null], 'CardBrand'],
    // An unparseable deadline silently read as "no deadline" is a case that never escalates.
    'an unreadable DueDate' => [['DueDate' => 'yesterday-ish'], 'DueDate'],
]);

it('treats an empty DueDate as absent, not as a refusal', function () {
    $case = CaseSnapshot::fromPayload(cmsCasePayload(['DueDate' => '']));

    expect($case->dueDate)->toBeNull()
        ->and(ProviderSnapshotCanonicaliser::date($case->dueDate))->toBe(ProviderSnapshotCanonicaliser::ABSENT);
});

it('reads a date-only DueDate as UTC rather than in the process\'s timezone', function () {
    // Parsed against a machine west of Greenwich, `2026-09-20T00:00:00` would land on the 19th and
    // the deadline escalation is built on would move with the server.
    $original = date_default_timezone_get();
    date_default_timezone_set('America/Los_Angeles');

    try {
        $case = CaseSnapshot::fromPayload(cmsCasePayload(['DueDate' => '2026-09-20T00:00:00']));

        expect($case->dueDate?->format('Y-m-d H:i'))->toBe('2026-09-20 00:00')
            ->and($case->dueDate?->getTimezone()->getName())->toBe('UTC');
    } finally {
        date_default_timezone_set($original);
    }
});

it('states the family only when the provider did, and null when it did not', function (?string $familyId, ?string $expected) {
    // Null and an empty string mean different things to the recorder: an empty reference would be
    // looked up and match nothing, while null says this provider stated no family.
    expect(CaseSnapshot::fromPayload(cmsCasePayload(['FamilyId' => $familyId]))->familyRef())->toBe($expected);
})->with([
    'a family' => ['FAM-1001', 'FAM-1001'],
    'an empty one' => ['', null],
    'absent' => [null, null],
    'whitespace' => ['   ', null],
]);
