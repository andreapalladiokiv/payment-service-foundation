<?php

declare(strict_types=1);

use Techork\PaymentService\ConnexPay\Dispute\ProviderSnapshotCanonicaliser;

/**
 * The eleven ConnexPay fields, in the order the contract fixes.
 *
 * `GetByUser` and `GetByResolvedDate` are the two CMS endpoints this key has to be computed from,
 * and they spell the same case differently — numbers against strings, an ISO timestamp against a
 * date — which is why every field below is passed through one of the canonicaliser's own
 * normalisers rather than being concatenated as it arrived. Building this array by hand out of raw
 * payload values would be testing the assembler rather than the contract: `CaseSnapshot::hashFields()`
 * assembles these eleven fields from a payload, and this file pins the hashing they are assembled
 * for.
 *
 * @param array<string, scalar|null> $overrides
 *
 * @return array<string, scalar|null>
 */
function connexPayCase(array $overrides = []): array
{
    return $overrides + [
        'FamilyId' => '778100',
        'CaseNumber' => '223344',
        'CaseType' => '1',
        'ReasonCode' => 'C08',
        'ResolutionTo' => null,
        'WinLoss' => null,
        'HasResponse' => true,
        'HasImage' => false,
        'IsSurrendered' => false,
        'DueDate' => ProviderSnapshotCanonicaliser::date('2026-09-19'),
        'NetPosition' => ProviderSnapshotCanonicaliser::amount('-20.00', 'USD'),
    ];
}

it('pins the eleven fields in the contract order to one known key', function () {
    // A literal digest, not a recomputation: the value of pinning it is that a change to the
    // field list, the pair format or the join stops matching this line. `CaseSnapshot::hashFields()`
    // assembles the same eleven fields in this order, so this digest is the contract it meets.
    expect(ProviderSnapshotCanonicaliser::v1()->canonicalise(connexPayCase()))
        ->toBe('v1:f9a29361e2609830fcfa2b5e44a47c5afc18491f2c5a95578f91edd9f242b51d');
});

it('prefixes the digest with the version and the digest is a sha256', function () {
    $key = ProviderSnapshotCanonicaliser::v1()->canonicalise(connexPayCase());

    expect($key)->toStartWith('v1:')
        ->and(substr($key, 3))->toMatch('/^[0-9a-f]{64}$/');
});

it('treats absent, null and the empty string as the same token', function () {
    $absent = ProviderSnapshotCanonicaliser::v1()->canonicalise(connexPayCase(['ReasonCode' => null]));
    $empty = ProviderSnapshotCanonicaliser::v1()->canonicalise(connexPayCase(['ReasonCode' => '']));

    // The CMS returns both spellings for the same underlying "not set", so the same case must
    // hash the same whichever endpoint reported it.
    expect($absent)->toBe($empty)
        ->and($absent)->toBe(ProviderSnapshotCanonicaliser::v1()->canonicalise(
            connexPayCase(['ReasonCode' => ProviderSnapshotCanonicaliser::ABSENT]),
        ))
        ->and(ProviderSnapshotCanonicaliser::value(null))->toBe('-')
        ->and(ProviderSnapshotCanonicaliser::value(''))->toBe('-')
        ->and(ProviderSnapshotCanonicaliser::date(null))->toBe('-')
        ->and(ProviderSnapshotCanonicaliser::date(''))->toBe('-')
        ->and(ProviderSnapshotCanonicaliser::amount(null, 'USD'))->toBe('-');
});

it('preserves the caller order and never sorts the fields', function () {
    $inContractOrder = ProviderSnapshotCanonicaliser::v1()->canonicalise(connexPayCase());
    $sorted = connexPayCase();
    ksort($sorted);

    // The order is part of the contract, so a sort would make two different field orders agree —
    // the opposite of what a versioned key wants.
    expect(ProviderSnapshotCanonicaliser::v1()->canonicalise($sorted))->not->toBe($inContractOrder);
});

it('normalises a date to Y-m-d in UTC rather than in the process timezone', function () {
    // 23:30 on the 19th at UTC-5 is the 20th in UTC, and a machine-local parse would call it the
    // 19th — the same case hashing differently on two servers.
    expect(ProviderSnapshotCanonicaliser::date('2026-09-19T23:30:00-05:00'))->toBe('2026-09-20')
        ->and(ProviderSnapshotCanonicaliser::date(new DateTimeImmutable('2026-09-19T23:30:00-05:00')))
        ->toBe('2026-09-20')
        // A date with no zone stated is read as UTC, not as the machine's local midnight.
        ->and(ProviderSnapshotCanonicaliser::date('2026-09-19'))->toBe('2026-09-19');
});

it('normalises an amount to integer minor units in the currency the payload states', function () {
    expect(ProviderSnapshotCanonicaliser::amount('-20.00', 'USD'))->toBe('-2000')
        ->and(ProviderSnapshotCanonicaliser::amount('20.00', 'USD'))->toBe('2000')
        // The payload's figure is a decimal amount, so a JSON integer is a major-unit figure:
        // 2000 is two thousand dollars, and the key carries 200000 minor units. Spelled out
        // because the `int` in the signature invites the other reading.
        ->and(ProviderSnapshotCanonicaliser::amount(2000, 'USD'))->toBe('200000')
        ->and(ProviderSnapshotCanonicaliser::amount(-20.0, 'USD'))->toBe('-2000')
        // No decimal places on the currency, so an integer here is already the minor unit and
        // nothing is scaled.
        ->and(ProviderSnapshotCanonicaliser::amount('2000', 'JPY'))->toBe('2000')
        // The same numeric value in two currencies is two different minor-unit counts, which is
        // why the currency is an explicit argument rather than something guessed from the API.
        ->and(ProviderSnapshotCanonicaliser::amount('20.00', 'KWD'))->toBe('20000');
});

it('keeps codes raw: case preserved and untrimmed', function () {
    // `C08` is not `c08`, and trimming would be a correction — and a corrected key stops matching
    // the provider's next statement of the same value.
    expect(ProviderSnapshotCanonicaliser::value('C08'))->toBe('C08')
        ->and(ProviderSnapshotCanonicaliser::value(' c08 '))->toBe(' c08 ')
        ->and(ProviderSnapshotCanonicaliser::v1()->canonicalise(connexPayCase(['ReasonCode' => 'C08'])))
        ->not->toBe(ProviderSnapshotCanonicaliser::v1()->canonicalise(connexPayCase(['ReasonCode' => 'c08'])));
});

it('renders a boolean the same way whichever endpoint spelled it', function () {
    // `HasResponse` arrives as a JSON boolean on one endpoint and as the string "true" on the
    // other, and the contract says the same case hashes the same on both.
    expect(ProviderSnapshotCanonicaliser::value(true))->toBe('true')
        ->and(ProviderSnapshotCanonicaliser::value('true'))->toBe('true')
        ->and(ProviderSnapshotCanonicaliser::value(false))->toBe('false')
        ->and(ProviderSnapshotCanonicaliser::v1()->canonicalise(connexPayCase(['HasResponse' => false])))
        ->toBe(ProviderSnapshotCanonicaliser::v1()->canonicalise(connexPayCase(['HasResponse' => 'false'])));
});

it('produces the same key for one case read through two differently shaped payloads', function () {
    // GetByUser: everything as strings, the date carrying a zone, the net position decimal.
    $byUser = connexPayCase([
        'CaseNumber' => '223344',
        'NetPosition' => ProviderSnapshotCanonicaliser::amount('-20.00', 'USD'),
        'DueDate' => ProviderSnapshotCanonicaliser::date('2026-09-19T00:00:00-05:00'),
    ]);

    // GetByResolvedDate: the same case, numbers instead of strings, a date with no zone.
    $byResolvedDate = connexPayCase([
        'CaseNumber' => 223344,
        'NetPosition' => ProviderSnapshotCanonicaliser::amount(-20.0, 'USD'),
        'DueDate' => ProviderSnapshotCanonicaliser::date('2026-09-19'),
    ]);

    expect(ProviderSnapshotCanonicaliser::v1()->canonicalise($byResolvedDate))
        ->toBe(ProviderSnapshotCanonicaliser::v1()->canonicalise($byUser));
});

it('refuses a snapshot with no fields, because a hash of nothing is a constant', function () {
    ProviderSnapshotCanonicaliser::v1()->canonicalise([]);
})->throws(InvalidArgumentException::class);

it('refuses an amount it cannot parse rather than hiding it as absent', function () {
    // Hashing an unreadable amount as "absent" would collide with a genuinely absent NetPosition,
    // which is a different case's key.
    ProviderSnapshotCanonicaliser::amount('twenty', 'USD');
})->throws(InvalidArgumentException::class);

it('refuses a currency the parser does not know', function () {
    ProviderSnapshotCanonicaliser::amount('20.00', 'XYZ');
})->throws(InvalidArgumentException::class);

it('refuses an empty currency code rather than reading the amount as unitless', function () {
    // Minor units are meaningless without the currency, and the refusal has to be the same one an
    // unknown code gets — a payload that arrived with no currency is a mapping bug, and hashing its
    // figure as though a scale were not needed would produce a key nothing else agrees with.
    ProviderSnapshotCanonicaliser::amount('20.00', '');
})->throws(InvalidArgumentException::class);

it('bumps the version so a changed field list cannot silently re-hash', function () {
    $v1 = ProviderSnapshotCanonicaliser::v1();
    $v2 = ProviderSnapshotCanonicaliser::forVersion('v2');

    expect($v2->version())->toBe('v2')
        ->and($v2->canonicalise(connexPayCase()))->toStartWith('v2:')
        ->and($v2->canonicalise(connexPayCase()))
        ->not->toBe($v1->canonicalise(connexPayCase()))
        ->and($v1->version())->toBe('v1');
});

it('refuses a version that is not a version', function (string $version) {
    ProviderSnapshotCanonicaliser::forVersion($version);
})->throws(InvalidArgumentException::class)->with(['2', 'v', 'v1x', 'V1', '']);
