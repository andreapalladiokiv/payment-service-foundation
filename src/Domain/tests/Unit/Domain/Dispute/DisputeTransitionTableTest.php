<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\ReasonCategory;
use Techork\PaymentService\Domain\Dispute\ValueObject\ReasonCodeMapper;

/**
 * The two tables, written out as the plan states them.
 *
 * These are not "tests of the enum's own match statement" — they are the places where a change to
 * a transition becomes visible as a change to a test. The aggregate's guards read the enums, so
 * widening a table without noticing is exactly the defect this file exists to prevent: a
 * transition added here on purpose has to be added below on purpose too.
 */

it('permits exactly the status transitions the plan lists', function () {
    expect(DisputeStatus::NeedsResponse->allowedTransitions())->toBe([
        DisputeStatus::UnderReview,
        DisputeStatus::Won,
        DisputeStatus::Lost,
        DisputeStatus::Accepted,
        DisputeStatus::Expired,
        DisputeStatus::Closed,
    ])
        ->and(DisputeStatus::UnderReview->allowedTransitions())->toBe([
            DisputeStatus::Won,
            DisputeStatus::Lost,
            DisputeStatus::Accepted,
            DisputeStatus::Closed,
        ])
        // The late win, and the only way out of LOST.
        ->and(DisputeStatus::Lost->allowedTransitions())->toBe([DisputeStatus::Won]);
});

it('makes WON, ACCEPTED, EXPIRED and CLOSED terminal', function (DisputeStatus $status) {
    expect($status->allowedTransitions())->toBe([])
        ->and($status->isTerminal())->toBeTrue()
        ->and($status->allows(DisputeStatus::UnderReview))->toBeFalse();
})->with([
    DisputeStatus::Won,
    DisputeStatus::Accepted,
    DisputeStatus::Expired,
    DisputeStatus::Closed,
]);

it('leaves the cases that are still waiting on somebody non-terminal', function (DisputeStatus $status) {
    expect($status->isTerminal())->toBeFalse()
        ->and($status->allows(DisputeStatus::Closed))->toBeTrue();
})->with([
    DisputeStatus::NeedsResponse,
    DisputeStatus::UnderReview,
]);

it('does not let a signal expire a case the network has taken under review', function () {
    // The one asymmetry in the table, kept rather than widened: expiry is ours to declare only
    // inside a response window we own.
    expect(DisputeStatus::UnderReview->allows(DisputeStatus::Expired))->toBeFalse()
        ->and(DisputeStatus::UnderReview->allows(DisputeStatus::NeedsResponse))->toBeFalse();
});

it('counts only WON and LOST as resolutions', function () {
    $resolutions = array_values(array_filter(
        DisputeStatus::cases(),
        static fn (DisputeStatus $status): bool => $status->isResolution(),
    ));

    expect($resolutions)->toBe([DisputeStatus::Won, DisputeStatus::Lost]);
});

it('permits exactly the submission transitions the plan lists', function () {
    expect(SubmissionState::None->allowedTransitions())->toBe([
        SubmissionState::Draft,
        // The ConnexPay operator route: a portal handoff has no staging step.
        SubmissionState::Submitted,
    ])
        ->and(SubmissionState::Draft->allowedTransitions())->toBe([
            SubmissionState::Submitted,
            SubmissionState::UploadPending,
        ])
        // The one backwards move on either axis.
        ->and(SubmissionState::UploadPending->allowedTransitions())->toBe([
            SubmissionState::Submitted,
            SubmissionState::Draft,
        ])
        ->and(SubmissionState::Submitted->allowedTransitions())->toBe([SubmissionState::Confirmed]);
});

it('makes CONFIRMED terminal and SUBMITTED merely acknowledged-awaiting', function () {
    expect(SubmissionState::Confirmed->allowedTransitions())->toBe([])
        ->and(SubmissionState::Confirmed->isTerminal())->toBeTrue()
        // SUBMITTED is not terminal even for the providers that will never move it: Stripe gives
        // no acknowledgement, and the state simply stays where it is.
        ->and(SubmissionState::Submitted->isTerminal())->toBeFalse()
        ->and(SubmissionState::UploadPending->isAwaitingProvider())->toBeTrue()
        ->and(SubmissionState::Draft->isAwaitingProvider())->toBeFalse();
});

it('keeps the two axes in disjoint vocabularies', function () {
    // The two enums are disjoint, and this is the type-level statement of the independence the
    // aggregate's tests assert behaviourally: no value of one appears in the other's table.
    $statusValues = array_map(static fn (DisputeStatus $s): string => $s->value, DisputeStatus::cases());
    $submissionValues = array_map(static fn (SubmissionState $s): string => $s->value, SubmissionState::cases());

    expect(array_intersect($statusValues, $submissionValues))->toBe([]);
});

it('keeps ARBITRATION in the stage vocabulary with no producer to reach it', function () {
    // The networks have the phase, so the value stays; nothing maps to it, and the aggregate
    // refuses both opening in it and moving into it. D1 reconsiders this, not a mapping table.
    expect(array_map(static fn (DisputeStage $s): string => $s->value, DisputeStage::cases()))
        ->toBe(['inquiry', 'chargeback', 'pre_arbitration', 'arbitration']);
});

it('keeps three networks\' reason codes apart rather than collapsing them', function () {
    $visa = DisputeReason::fromProviderCode(CardBrand::Visa, '13.1');
    $mastercard = DisputeReason::fromProviderCode(CardBrand::Mastercard, '4853');
    $amex = DisputeReason::fromProviderCode(CardBrand::Amex, 'C08');

    expect($visa->rawCode)->toBe('13.1')
        ->and($mastercard->rawCode)->toBe('4853')
        ->and($amex->rawCode)->toBe('C08')
        ->and($visa->cardBrand)->toBe(CardBrand::Visa)
        ->and($mastercard->cardBrand)->toBe(CardBrand::Mastercard)
        ->and($amex->cardBrand)->toBe(CardBrand::Amex)
        // The same question asked by three networks, and still three different values: the hint
        // logic and F6's requirement sets key off the pair.
        ->and($visa->category)->toBe(ReasonCategory::NotReceived)
        ->and($mastercard->category)->toBe(ReasonCategory::NotReceived)
        ->and($amex->category)->toBe(ReasonCategory::NotReceived)
        ->and($visa)->not->toEqual($mastercard)
        ->and($mastercard)->not->toEqual($amex);
});

it('does not answer one network\'s code through another network\'s entry', function () {
    expect(ReasonCodeMapper::categorise(CardBrand::Visa, '4853'))->toBe(ReasonCategory::Uncategorised)
        ->and(ReasonCodeMapper::categorise(CardBrand::Mastercard, 'C08'))->toBe(ReasonCategory::Uncategorised)
        ->and(ReasonCodeMapper::categorise(CardBrand::Amex, '13.1'))->toBe(ReasonCategory::Uncategorised)
        ->and(ReasonCodeMapper::knows(CardBrand::Visa, '13.1'))->toBeTrue()
        ->and(ReasonCodeMapper::knows(CardBrand::Visa, '4853'))->toBeFalse();
});

it('answers Uncategorised for a network whose list this table does not carry', function () {
    // Discover, JCB and UnionPay share numeric ranges with the others by coincidence, not by
    // mapping, so they borrow nothing.
    expect(ReasonCodeMapper::categorise(CardBrand::Discover, '4853'))->toBe(ReasonCategory::Uncategorised)
        ->and(ReasonCodeMapper::categorise(CardBrand::JCB, '13.1'))->toBe(ReasonCategory::Uncategorised);
});
