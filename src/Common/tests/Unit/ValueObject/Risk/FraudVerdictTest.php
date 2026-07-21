<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Risk\FraudDecision;
use Techork\PaymentService\Common\ValueObject\Risk\FraudVerdict;

it('exposes the four fraud decisions with expected string values', function () {
    expect(FraudDecision::Approve->value)->toBe('approve')
        ->and(FraudDecision::Decline->value)->toBe('decline')
        ->and(FraudDecision::NotReviewed->value)->toBe('not_reviewed')
        ->and(FraudDecision::Errored->value)->toBe('errored')
        ->and(FraudDecision::cases())->toHaveCount(4);
});

it('reports approved and declined verdicts', function () {
    expect((new FraudVerdict(FraudDecision::Approve))->isApproved())->toBeTrue()
        ->and((new FraudVerdict(FraudDecision::Approve))->isDeclined())->toBeFalse()
        ->and((new FraudVerdict(FraudDecision::Decline, 'CC_BLOCKED'))->isDeclined())->toBeTrue();
});

it('treats not-reviewed and errored as inconclusive but approve/decline as conclusive', function (FraudDecision $decision, bool $inconclusive) {
    expect((new FraudVerdict($decision))->isInconclusive())->toBe($inconclusive);
})->with([
    [FraudDecision::Approve, false],
    [FraudDecision::Decline, false],
    [FraudDecision::NotReviewed, true],
    [FraudDecision::Errored, true],
]);

it('carries an optional reason code and provider reference', function () {
    $verdict = new FraudVerdict(FraudDecision::Decline, 'HIGH_RISK', 'forter-txn-123');

    expect($verdict->reasonCode)->toBe('HIGH_RISK')
        ->and($verdict->reference)->toBe('forter-txn-123');
});
