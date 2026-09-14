<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceFormat;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceItem;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;

it('carries text by default', function () {
    // Most of what a project collects is prose, and an item that made every caller name the
    // format would be naming the only one it can have.
    $item = new EvidenceItem(EvidenceType::CustomerCorrespondence, 'buyer wrote in on the 4th');

    expect($item->type)->toBe(EvidenceType::CustomerCorrespondence)
        ->and($item->content)->toBe('buyer wrote in on the 4th')
        ->and($item->format)->toBe(EvidenceFormat::Text)
        ->and($item->format->isFile())->toBeFalse();
});

it('refuses an item carrying nothing', function () {
    // An empty item is not "we have not attached this yet" — that is the item's absence
    // from the package. Stored here it would count as supplied and answer the requirement.
    expect(fn () => new EvidenceItem(EvidenceType::ProofOfDeliveryOrService, ''))
        ->toThrow(InvalidArgumentException::class, 'proof_of_delivery_or_service');
});

it('carries a base64 file', function () {
    $encoded = base64_encode('%PDF-1.4 a delivery note');

    $item = new EvidenceItem(EvidenceType::ProofOfDeliveryOrService, $encoded, EvidenceFormat::Pdf);

    expect($item->format->isFile())->toBeTrue()
        ->and(base64_decode($item->content, true))->toBe('%PDF-1.4 a delivery note');
});

it('refuses a file handed over as raw bytes', function () {
    // The standing integration mistake: both file flows want base64, and raw bytes come
    // back from the provider as a decode error that reads like a provider fault. Rejected
    // at construction instead, where the stack trace names the code that did it.
    expect(fn () => new EvidenceItem(EvidenceType::ProofOfDeliveryOrService, '%PDF-1.4 raw', EvidenceFormat::Pdf))
        ->toThrow(InvalidArgumentException::class, 'proof_of_delivery_or_service');
});

it('does not run the base64 check over text', function () {
    // A correspondence thread contains punctuation, quotes and newlines, none of which is
    // base64 — validating text as if it were an upload would reject every real one.
    $item = new EvidenceItem(
        EvidenceType::CustomerCorrespondence,
        "Buyer: \"it never arrived\"\nUs: sending a replacement.",
    );

    expect($item->content)->toContain('"it never arrived"');
});

it('carries a JPEG the portal will accept', function () {
    $item = new EvidenceItem(
        EvidenceType::ProofOfDeliveryOrService,
        base64_encode("\xFF\xD8\xFF a photograph of the signed receipt"),
        EvidenceFormat::Jpeg,
    );

    expect($item->format)->toBe(EvidenceFormat::Jpeg);
});
