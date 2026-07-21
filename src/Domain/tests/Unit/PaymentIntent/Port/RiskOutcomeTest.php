<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Risk\FraudDecision;
use Techork\PaymentService\Common\ValueObject\Risk\FraudVerdict;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskAction;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskOutcome;

it('exposes the three risk actions with expected string values', function () {
    expect(RiskAction::Require3ds->value)->toBe('require_3ds')
        ->and(RiskAction::Skip3ds->value)->toBe('skip_3ds')
        ->and(RiskAction::Allow->value)->toBe('allow')
        ->and(RiskAction::cases())->toHaveCount(3);
});

it('reports whether it requires or skips 3DS', function () {
    $require = new RiskOutcome(RiskAction::Require3ds);
    $skip = new RiskOutcome(RiskAction::Skip3ds);
    $allow = new RiskOutcome(RiskAction::Allow);

    expect($require->requiresThreeDS())->toBeTrue()
        ->and($require->skipsThreeDS())->toBeFalse()
        ->and($skip->skipsThreeDS())->toBeTrue()
        ->and($allow->requiresThreeDS())->toBeFalse()
        ->and($allow->skipsThreeDS())->toBeFalse();
});

it('carries the fraud reference, verdict and reason', function () {
    $verdict = new FraudVerdict(FraudDecision::Decline, 'HIGH_RISK');
    $outcome = new RiskOutcome(RiskAction::Require3ds, 'uuid-1', $verdict, 'declined by provider');

    expect($outcome->fraudReference)->toBe('uuid-1')
        ->and($outcome->verdict)->toBe($verdict)
        ->and($outcome->reason)->toBe('declined by provider');
});
