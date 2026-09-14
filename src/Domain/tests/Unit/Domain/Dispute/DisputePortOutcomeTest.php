<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Domain\Dispute\Port\AcceptOutcome;
use Techork\PaymentService\Domain\Dispute\Port\Request\AcceptDisputeRequest;
use Techork\PaymentService\Domain\Dispute\Port\Request\SubmitEvidenceRequest;
use Techork\PaymentService\Domain\Dispute\Port\SubmissionOutcome;
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage;
use Techork\PaymentService\Common\ValueObject\CardBrand;

/**
 * What the submission and acceptance ports are allowed to say.
 *
 * Both outcomes exist because the aggregate records a fact rather than an assumption: a submission
 * lands in one of three states of *our* side of the case, and an acceptance answers what the
 * provider did with it. The refusals below are the other half of that — the two states no call may
 * claim, and the outcome that must not be reported as a concession.
 */

it('lets a submission report the three states a call can actually reach', function (SubmissionState $state) {
    expect(new SubmissionOutcome($state)->state)->toBe($state);
})->with([
    SubmissionState::Draft,
    SubmissionState::UploadPending,
    SubmissionState::Submitted,
]);

it('refuses a submission that reports nothing or the provider\'s own acknowledgement', function (SubmissionState $state) {
    // NONE is the absence of a submission, which a call that just returned did not produce; and
    // CONFIRMED is never synthesised — it is the provider's `HasResponse`, which arrives through
    // the recorder. An outcome claiming it would file an operator's "I filed it" as the provider's
    // word, which is the one confusion F9's whole flow is built to avoid.
    expect(fn () => new SubmissionOutcome($state))->toThrow(InvalidArgumentException::class);
})->with([
    SubmissionState::None,
    SubmissionState::Confirmed,
]);

it('carries the state instead of returning void, because the caller cannot derive it', function () {
    // Stripe's `submit: false` stages evidence; Nuvei's upload lands in between; a caller holding
    // only "the call returned" would have to guess which of the three commands to dispatch.
    expect(new SubmissionOutcome(SubmissionState::Draft)->state)->toBe(SubmissionState::Draft)
        ->and(new SubmissionOutcome(SubmissionState::UploadPending)->state)->toBe(SubmissionState::UploadPending);
});

it('reports a full acceptance, a partial one, and a case the provider had already closed', function () {
    $partial = new Money(7500, new Currency('USD'));

    $full = AcceptOutcome::acceptedInFull();
    $part = AcceptOutcome::acceptedPartially($partial);
    // The one a `void` return would lose: our call did not decide this, so the aggregate must not
    // record a concession from it.
    $closed = AcceptOutcome::alreadyClosed();

    expect($full->wasAccepted())->toBeTrue()
        ->and($full->wasAlreadyClosed())->toBeFalse()
        ->and($full->wasPartial())->toBeFalse()
        // Null is the whole disputed amount, never "unknown" — an unknown outcome is alreadyClosed.
        ->and($full->acceptedAmount())->toBeNull()
        ->and($part->wasAccepted())->toBeTrue()
        ->and($part->wasPartial())->toBeTrue()
        ->and($part->acceptedAmount()?->equals($partial))->toBeTrue()
        ->and($closed->wasAccepted())->toBeFalse()
        ->and($closed->wasAlreadyClosed())->toBeTrue()
        ->and($closed->wasPartial())->toBeFalse()
        ->and($closed->acceptedAmount())->toBeNull();
});

it('refuses a partial acceptance of nothing, which concedes nothing to report', function () {
    expect(fn () => AcceptOutcome::acceptedPartially(new Money(0, new Currency('USD'))))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => AcceptOutcome::acceptedPartially(new Money(-100, new Currency('USD'))))
        ->toThrow(InvalidArgumentException::class);
});

it('carries the partial amount on the request, and refuses one that is not an amount', function () {
    $disputeId = DisputeId::generate();

    // A full acceptance is the default: Stripe's close concedes the whole case and has no amount.
    expect((new AcceptDisputeRequest($disputeId))->partialAmount)->toBeNull()
        ->and((new AcceptDisputeRequest($disputeId, new Money(7500, new Currency('USD'))))->partialAmount?->getAmount())
        ->toBe('7500')
        ->and(fn () => new AcceptDisputeRequest($disputeId, new Money(0, new Currency('USD'))))
        ->toThrow(InvalidArgumentException::class);
});

it('carries the assembled evidence together with the question whether to send it', function () {
    $disputeId = DisputeId::generate();
    $package = new EvidencePackage(CardBrand::Visa, '13.1');

    // Stripe's `submit: false` stages the evidence on the case, invisible to the issuer, which is
    // what lets a human review what is about to be filed.
    $staged = new SubmitEvidenceRequest($disputeId, $package, stageOnly: true);
    $sent = new SubmitEvidenceRequest($disputeId, $package);

    expect($staged->stageOnly)->toBeTrue()
        ->and($sent->stageOnly)->toBeFalse()
        ->and($staged->evidence->cardBrand)->toBe(CardBrand::Visa)
        ->and($staged->evidence->reasonCode)->toBe('13.1')
        // A submission with no evidence is a concession wearing a response's clothes, so the
        // package is not optional — an empty one is the caller's to say explicitly.
        ->and($sent->evidence->items())->toBe([]);
});
