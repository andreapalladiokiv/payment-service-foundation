<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceItem;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceRequirements;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;

it('holds what it was assembled with, in the order it was given', function () {
    $package = new EvidencePackage(CardBrand::Visa, '13.1', [
        new EvidenceItem(EvidenceType::ProofOfDeliveryOrService, 'carrier scan'),
        new EvidenceItem(EvidenceType::CustomerCorrespondence, 'support thread'),
    ]);

    expect($package->cardBrand)->toBe(CardBrand::Visa)
        ->and($package->reasonCode)->toBe('13.1')
        ->and($package->items())->toHaveCount(2)
        ->and($package->has(EvidenceType::ProofOfDeliveryOrService))->toBeTrue()
        ->and($package->item(EvidenceType::ProofOfDeliveryOrService)?->content)->toBe('carrier scan')
        ->and($package->has(EvidenceType::StatementDescriptor))->toBeFalse()
        ->and($package->item(EvidenceType::StatementDescriptor))->toBeNull();
});

it('accepts an empty package', function () {
    // A case arrives needing an answer and no evidence has been collected yet. That is a
    // state the collection stage passes through, and it must be representable — the
    // requirement table is what reports it as incomplete.
    $package = new EvidencePackage(CardBrand::Visa, '13.1');

    expect($package->items())->toBe([])
        ->and(EvidenceRequirements::for(CardBrand::Visa, '13.1')->missingFrom($package))
        ->toBe(EvidenceRequirements::for(CardBrand::Visa, '13.1')->requiredFromProject());
});

it('grows by copy, leaving the package it came from alone', function () {
    // The evidence is assembled over hours by more than one code path. A mutable package
    // would let a submission go out holding whatever a later step happened to have added.
    $started = new EvidencePackage(CardBrand::Visa, '13.1');
    $grown = $started->with(new EvidenceItem(EvidenceType::CancellationPolicy, 'policy v3'));

    expect($started->items())->toBe([])
        ->and($grown->items())->toHaveCount(1)
        ->and($grown->has(EvidenceType::CancellationPolicy))->toBeTrue();
});

it('refuses a second item for a fact it already carries', function () {
    // One item per type. Two delivery notes are either the same fact twice or a second
    // document somebody has to choose between — and keeping whichever arrived last would
    // make that choice silently, in favour of the wrong one half the time.
    expect(fn () => new EvidencePackage(CardBrand::Visa, '13.1', [
        new EvidenceItem(EvidenceType::ProofOfDeliveryOrService, 'carrier scan'),
        new EvidenceItem(EvidenceType::ProofOfDeliveryOrService, 'the other carrier scan'),
    ]))->toThrow(InvalidArgumentException::class, 'proof_of_delivery_or_service');
});

it('refuses a duplicate added through with() as well', function () {
    // The check lives in the constructor, so the growing path cannot bypass it.
    $package = new EvidencePackage(CardBrand::Visa, '13.1', [
        new EvidenceItem(EvidenceType::ProofOfDeliveryOrService, 'carrier scan'),
    ]);

    expect(fn () => $package->with(new EvidenceItem(EvidenceType::ProofOfDeliveryOrService, 'a second one')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a package that does not name the reason code it answers', function () {
    // The pair is what a package is judged against. An empty code is the same hazard the
    // idempotency key guards against: a component that matches everything.
    expect(fn () => new EvidencePackage(CardBrand::Visa, ''))
        ->toThrow(InvalidArgumentException::class);
});
