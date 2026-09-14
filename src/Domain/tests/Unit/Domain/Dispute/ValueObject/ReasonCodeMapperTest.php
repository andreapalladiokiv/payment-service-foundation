<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\ReasonCodeNetwork;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\ReasonCategory;
use Techork\PaymentService\Domain\Dispute\ValueObject\ReasonCodeMapper;

/**
 * The category table, and its agreement with the one authority on which network owns a code.
 *
 * `ReasonCodeMapper` answers `(network, code) → category`; `ReasonCodeNetwork` answers
 * `code → network`. They hold the same three code sets, which is a drift risk taken on purpose: the
 * mapper is a conservative seed of codes whose *category* is known, while the namespace is what a
 * code is *placed* by, and the placement has to be answerable inside `src/Nuvei/`, which cannot see
 * this package.
 *
 * The pin below is the whole return on that trade. A code added here under the wrong network — the
 * one mistake that would matter, since it would categorise one network's code by another's meaning
 * — cannot pass, and a code added to the namespace without a category stays visibly
 * `Uncategorised` rather than silently absent.
 */

/**
 * The mapper's table, read through reflection: it is private because nothing should be reading it
 * except through `categorise()`/`knows()`, and this test is the exception that proves the rule.
 *
 * @return array<string, array<string, ReasonCategory>>
 */
function reasonCodeCategoryTable(): array
{
    /** @var array<string, array<string, ReasonCategory>> $table */
    $table = new ReflectionClass(ReasonCodeMapper::class)->getReflectionConstant('TABLE')?->getValue();

    expect($table)->toBeArray()->not->toBeEmpty();

    return $table;
}

it('claims no code for a network that does not issue it', function () {
    // The invariant, stated once at the table level: every entry the mapper holds is a code the
    // namespace places with that same network. A code pasted under the wrong brand here is the one
    // defect that turns into a wrong category on a real case, and it cannot survive this.
    $checked = 0;

    foreach (reasonCodeCategoryTable() as $brand => $codes) {
        // The literal's numeric keys — Mastercard's `4853` — arrive from reflection as ints, since
        // PHP casts them on the way in. Invisible everywhere else, because `isset` casts a numeric
        // string back; it only shows up in a test that walks the table.
        foreach (array_map(strval(...), array_keys($codes)) as $code) {
            expect(ReasonCodeNetwork::issuing($code))->toBe(
                CardBrand::from($brand),
                sprintf('"%s" is categorised under %s and is not a code that network issues.', $code, $brand),
            );

            $checked++;
        }
    }

    // Guards the guard: a renamed or emptied constant would otherwise walk zero codes and pass.
    expect($checked)->toBeGreaterThan(50);
});

it('leaves the completeness gap a refusal rather than a category it cannot justify', function () {
    // Both tables are seeded from the same lists, so today every code the namespace places is also
    // categorised, and there is no "network known, category unknown" case to construct. What there
    // *is* is the other gap, and it is the one that will actually be hit: `4808` is a real
    // Mastercard code that neither list holds. Nothing places it — not by shape, which would answer
    // Mastercard — so a Nuvei delivery carrying it cannot establish a brand at all, and the
    // adapter's answer is to refuse loudly rather than file the case against a network it
    // recognised by resemblance.
    expect(ReasonCodeNetwork::issuing('4808'))->toBeNull()
        ->and(ReasonCodeMapper::knows(CardBrand::Mastercard, '4808'))->toBeFalse();

    // Where a network *is* stated by the payload — ConnexPay's numeric `CardBrand` — the same code
    // is recordable and reads as visibly incomplete instead, which is the `Uncategorised` half of
    // the rule and not a failure.
    $reason = DisputeReason::fromProviderCode(CardBrand::Mastercard, '4808');

    expect($reason->cardBrand)->toBe(CardBrand::Mastercard)
        ->and($reason->category)->toBe(ReasonCategory::Uncategorised)
        ->and($reason->rawCode)->toBe('4808');
});

it('still refuses to answer one network\'s code through another network\'s entry', function () {
    // The mapper is keyed per network and the namespaces do not cross, so a code asked about under
    // the wrong network is unknown there — which is what keeps Visa's `13.1`, Mastercard's `4853`
    // and Amex's `C08` three different answers rather than one collapsed meaning.
    expect(ReasonCodeMapper::categorise(CardBrand::Visa, '4853'))->toBe(ReasonCategory::Uncategorised)
        ->and(ReasonCodeMapper::categorise(CardBrand::Mastercard, 'C08'))->toBe(ReasonCategory::Uncategorised)
        ->and(ReasonCodeMapper::categorise(CardBrand::Amex, '13.1'))->toBe(ReasonCategory::Uncategorised)
        ->and(ReasonCodeMapper::knows(CardBrand::Visa, '13.1'))->toBeTrue()
        ->and(ReasonCodeMapper::knows(CardBrand::Visa, '4853'))->toBeFalse();
});
