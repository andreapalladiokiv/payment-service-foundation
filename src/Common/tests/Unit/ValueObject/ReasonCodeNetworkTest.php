<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\ReasonCodeNetwork;

/**
 * Reading a network off a dispute reason code.
 *
 * The two things this must get right are opposite halves of one rule: a code a network *does*
 * publish has to come back as that network, and a code that merely looks like one has to come back
 * as nothing at all. The second is the one that goes wrong quietly — a shape is a pattern we
 * noticed, and filing a case against a network we pattern-matched is worse than failing to file it.
 */

it('places each network\'s codes in that network\'s own namespace', function () {
    // The three shapes: Visa's dotted, Mastercard's four digits, Amex's letter — and more than one
    // code from each family, since a namespace is a range and not a single sample.
    expect(ReasonCodeNetwork::issuing('10.4'))->toBe(CardBrand::Visa)
        ->and(ReasonCodeNetwork::issuing('13.9'))->toBe(CardBrand::Visa)
        ->and(ReasonCodeNetwork::issuing('4853'))->toBe(CardBrand::Mastercard)
        ->and(ReasonCodeNetwork::issuing('4863'))->toBe(CardBrand::Mastercard)
        ->and(ReasonCodeNetwork::issuing('C08'))->toBe(CardBrand::Amex)
        ->and(ReasonCodeNetwork::issuing('P23'))->toBe(CardBrand::Amex);
});

it('answers null for a code no list carries, rather than the network it resembles', function () {
    // Every one of these matches a namespace's *shape* and is not a code in the list:
    //   - `13.10` is Visa-dotted and one digit too long — the shape says Visa, the list does not;
    //   - `9999` and `1234` are four digits, i.e. Mastercard-shaped, and are not Mastercard codes;
    //   - `Q99` is Amex-shaped and Amex issues no `Q` family here;
    //   - the empty string is what a payload with a blank reason would leave behind.
    // Inferring from shape would answer four networks for these. Doing so is the failure mode this
    // test exists for: a case filed against the wrong network's evidence requirements.
    expect(ReasonCodeNetwork::issuing('13.10'))->toBeNull()
        ->and(ReasonCodeNetwork::issuing('9999'))->toBeNull()
        ->and(ReasonCodeNetwork::issuing('1234'))->toBeNull()
        ->and(ReasonCodeNetwork::issuing('Q99'))->toBeNull()
        ->and(ReasonCodeNetwork::issuing(''))->toBeNull();
});

it('answers no network for one whose list it does not carry at all', function () {
    // Discover, JCB and UnionPay run their own codes and this table holds none of them, so there is
    // nothing to place — including for the numeric codes that would otherwise look like
    // Mastercard's. An adapter that knows one of these networks states the brand itself; this is
    // only the way in for a payload that states nothing but a code.
    foreach (['8002', '2020', 'A1', 'J95', '62'] as $code) {
        expect(ReasonCodeNetwork::issuing($code))->toBeNull();
    }
});
