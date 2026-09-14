<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceItem;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceRequirements;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;

/**
 * The three pairs F6's "Done when" names, in the order it names them. One list, so the
 * "three different sets" assertion and the "every mapped pair resolves" assertions cannot
 * drift apart — an entry added to one and forgotten in the other is how a table silently
 * loses a reason code.
 *
 * @return list<array{CardBrand, string}>
 */
function evidencePairsUnderTest(): array
{
    return [
        [CardBrand::Visa, '13.1'],
        [CardBrand::Mastercard, '4837'],
        [CardBrand::Visa, '10.4'],
    ];
}

/** @return list<string> */
function evidenceTypeValues(array $types): array
{
    return array_map(static fn (EvidenceType $type): string => $type->value, $types);
}

it('answers the three pairs F6 names with three different requirement sets', function () {
    $requirements = array_map(
        static fn (array $pair): EvidenceRequirements => EvidenceRequirements::for($pair[0], $pair[1]),
        evidencePairsUnderTest(),
    );

    $whole = array_map(
        static fn (EvidenceRequirements $r): array => [
            evidenceTypeValues($r->suppliedBySystem()),
            evidenceTypeValues($r->requiredFromProject()),
        ],
        $requirements,
    );

    expect($whole[0])->not->toEqual($whole[1])
        ->and($whole[0])->not->toEqual($whole[2])
        ->and($whole[1])->not->toEqual($whole[2]);
});

it('asks a different amount of the project for each of those three pairs', function () {
    // The stronger half of the claim above. Two pairs could differ only in what the system
    // attaches — a difference no project can act on — and that would satisfy "different
    // sets" while leaving the three reason codes asking the merchant for the same thing.
    $projects = array_map(
        static fn (array $pair): array => evidenceTypeValues(
            EvidenceRequirements::for($pair[0], $pair[1])->requiredFromProject(),
        ),
        evidencePairsUnderTest(),
    );

    expect($projects[0])->not->toEqual($projects[1])
        ->and($projects[0])->not->toEqual($projects[2])
        ->and($projects[1])->not->toEqual($projects[2]);
});

it('files every requirement under the group its own type declares', function () {
    foreach (evidencePairsUnderTest() as [$brand, $code]) {
        $requirements = EvidenceRequirements::for($brand, $code);

        foreach ($requirements->suppliedBySystem() as $type) {
            expect($type->isSystemSupplied())->toBeTrue();
        }

        foreach ($requirements->requiredFromProject() as $type) {
            expect($type->isSystemSupplied())->toBeFalse();
        }

        $overlap = array_filter(
            $requirements->suppliedBySystem(),
            static fn (EvidenceType $type): bool => in_array($type, $requirements->requiredFromProject(), true),
        );

        expect($overlap)->toBe([]);
    }
});

it('refuses a template that files a type under the wrong group', function () {
    // The two lists are not two arrays of the same kind. If they were, "the system supplies
    // it" would be a comment rather than a rule, and a template could ask a project for a
    // fact it has no way to produce.
    expect(fn () => new EvidenceRequirements(CardBrand::Visa, '13.1', [EvidenceType::CancellationPolicy], []))
        ->toThrow(InvalidArgumentException::class, 'cancellation_policy');

    expect(fn () => new EvidenceRequirements(CardBrand::Visa, '13.1', [], [EvidenceType::AvsCvvResult]))
        ->toThrow(InvalidArgumentException::class, 'avs_cvv_result');
});

it('refuses a template that lists one type twice', function () {
    expect(fn () => new EvidenceRequirements(
        CardBrand::Visa,
        '13.1',
        [EvidenceType::AvsCvvResult, EvidenceType::AvsCvvResult],
        [],
    ))->toThrow(InvalidArgumentException::class, 'avs_cvv_result');
});

it('refuses a template with no reason code', function () {
    // Mirrors the rule the idempotency key already carries: no component of a key may be
    // empty. An empty code would be a wildcard answering for every pair at once.
    expect(fn () => new EvidenceRequirements(CardBrand::Visa, '', [], []))
        ->toThrow(InvalidArgumentException::class);
});

it('does not ask for evidence a card-present transaction cannot produce', function () {
    // Visa 10.4 is a transaction at a terminal. There is no buyer at a remote checkout, so
    // there is no IP and no 3DS run — asking for either would send a project looking for
    // evidence that cannot exist and report its absence as the project's failure.
    $requirements = EvidenceRequirements::for(CardBrand::Visa, '10.4');

    expect($requirements->suppliedBySystem())->not->toContain(EvidenceType::BuyerIpAddress)
        ->and($requirements->suppliedBySystem())->not->toContain(EvidenceType::ThreeDsStatusAndLiabilityShift)
        ->and($requirements->requiredFromProject())->toBe([EvidenceType::ProofOfDeliveryOrService]);
});

it('asks a fraud reason for the accepted terms and a not-received reason for the cancellation trail', function () {
    $fraud = EvidenceRequirements::for(CardBrand::Mastercard, '4837');
    $notReceived = EvidenceRequirements::for(CardBrand::Visa, '13.1');

    expect($fraud->isRequiredFromProject(EvidenceType::TermsOfServiceAcceptance))->toBeTrue()
        ->and($fraud->isRequiredFromProject(EvidenceType::CancellationPolicy))->toBeFalse()
        ->and($notReceived->isRequiredFromProject(EvidenceType::CancellationPolicy))->toBeTrue()
        ->and($notReceived->isRequiredFromProject(EvidenceType::TermsOfServiceAcceptance))->toBeFalse();
});

it('answers two networks\' "not received" codes with the same set, from two entries', function () {
    // Mastercard 4853 and Visa 13.1 ask the same question, so they get the same answer. What
    // this asserts is that the agreement is a coincidence of two table entries and not a
    // shared one: the lookup is keyed on the pair, and either entry can change alone.
    // Compared as sets rather than as objects, because the pair is part of each one and the
    // pairs differ — comparing the objects would only re-assert that they are keyed apart.
    $mastercard = EvidenceRequirements::for(CardBrand::Mastercard, '4853');
    $visa = EvidenceRequirements::for(CardBrand::Visa, '13.1');

    expect(evidenceTypeValues($mastercard->suppliedBySystem()))
        ->toBe(evidenceTypeValues($visa->suppliedBySystem()))
        ->and(evidenceTypeValues($mastercard->requiredFromProject()))
        ->toBe(evidenceTypeValues($visa->requiredFromProject()));
});

it('keys on the brand as well as the code', function () {
    // Visa 13.1 is mapped; Mastercard 13.1 is not a code that brand issues. A lookup that
    // ignored the brand would answer for it confidently, and the answer would be Visa's.
    expect(EvidenceRequirements::tryFor(CardBrand::Mastercard, '13.1'))->toBeNull()
        ->and(EvidenceRequirements::tryFor(CardBrand::Visa, '13.1'))->not->toBeNull();
});

it('matches the code exactly as the network writes it', function () {
    // Amex codes are upper case. A lower-cased one, or one with stray whitespace, is a
    // different string and is surfaced as unmapped rather than quietly resolved — guessing
    // at a near-match is how a normalised reason enum comes back in through the side door.
    expect(EvidenceRequirements::tryFor(CardBrand::Amex, 'c08'))->toBeNull()
        ->and(EvidenceRequirements::tryFor(CardBrand::Amex, ' C08'))->toBeNull()
        ->and(EvidenceRequirements::tryFor(CardBrand::Amex, 'C08'))->not->toBeNull();

    // Visa 13.1 is not 13, and not 131. The code is a string because 13.1 is not an
    // integer, and reading it as one is the collapse F1 refuses.
    expect(EvidenceRequirements::tryFor(CardBrand::Visa, '13'))->toBeNull()
        ->and(EvidenceRequirements::tryFor(CardBrand::Visa, '131'))->toBeNull();
});

it('refuses an unmapped pair by name, and offers a nullable form beside it', function () {
    // The refusal names both halves of the key: an adapter reading a log line has to be
    // able to tell which mapping is missing without reproducing the lookup.
    expect(fn () => EvidenceRequirements::for(CardBrand::Amex, 'F24'))
        ->toThrow(RuntimeException::class, 'No evidence requirements are mapped for amex reason code "F24"');
});

it('does not count a system-supplied type as outstanding', function () {
    // The system's evidence is ours to attach, so it is never a debt the project can be
    // chased for — a package holding only the project's four items answers Visa 13.1.
    $requirements = EvidenceRequirements::for(CardBrand::Visa, '13.1');

    $package = new EvidencePackage(CardBrand::Visa, '13.1', [
        new EvidenceItem(EvidenceType::ProofOfDeliveryOrService, 'carrier scan, signed'),
        new EvidenceItem(EvidenceType::CustomerCorrespondence, 'support thread'),
        new EvidenceItem(EvidenceType::CancellationPolicy, 'policy v3, in force at sale'),
        new EvidenceItem(EvidenceType::CancellationConfirmation, 'cancellation acknowledgement'),
    ]);

    expect($requirements->missingFrom($package))->toBe([])
        ->and($requirements->satisfiedBy($package))->toBeTrue();
});

it('reports what the project still owes, in table order', function () {
    $requirements = EvidenceRequirements::for(CardBrand::Visa, '13.1');

    $package = new EvidencePackage(CardBrand::Visa, '13.1', [
        new EvidenceItem(EvidenceType::CancellationConfirmation, 'cancellation acknowledgement'),
    ]);

    expect($requirements->missingFrom($package))->toBe([
        EvidenceType::ProofOfDeliveryOrService,
        EvidenceType::CustomerCorrespondence,
        EvidenceType::CancellationPolicy,
    ])->and($requirements->satisfiedBy($package))->toBeFalse();
});

it('refuses to judge a package assembled for another pair', function () {
    // The one mistake this table exists to prevent: a package coming back complete because
    // it was measured against the wrong reason code's template.
    $requirements = EvidenceRequirements::for(CardBrand::Visa, '13.1');

    expect(fn () => $requirements->missingFrom(new EvidencePackage(CardBrand::Mastercard, '4837')))
        ->toThrow(InvalidArgumentException::class);
});
