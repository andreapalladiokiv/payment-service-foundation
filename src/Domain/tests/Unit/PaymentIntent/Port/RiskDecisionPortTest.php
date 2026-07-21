<?php

declare(strict_types=1);

use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\Risk\ConnectionContext;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\RiskAssessmentRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskAction;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskDecisionPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskPhase;

it('can be implemented to decide a risk action from an assessment request', function () {
    $port = new class implements RiskDecisionPort
    {
        public function decide(RiskAssessmentRequest $request): RiskOutcome
        {
            // Trivial stand-in policy: any registration-phase check forces 3DS.
            $action = $request->phase === RiskPhase::Registration
                ? RiskAction::Require3ds
                : RiskAction::Skip3ds;

            return new RiskOutcome($action, $request->fraudReference);
        }
    };

    $request = new RiskAssessmentRequest(
        amount: Money::USD(0),
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(6, 2030), new Holder('A B')),
        billing: new BillingAddress('A', 'B', '1 Main St', 'Town', new Country('US'), '10001'),
        connection: new ConnectionContext('203.0.113.7', 'Mozilla/5.0'),
        phase: RiskPhase::Registration,
        fraudReference: 'uuid-42',
    );

    $outcome = $port->decide($request);

    expect($outcome->requiresThreeDS())->toBeTrue()
        ->and($outcome->fraudReference)->toBe('uuid-42');
});

it('carries an optional gateway id for per-gateway decisions', function () {
    $request = new RiskAssessmentRequest(
        amount: Money::USD(5000),
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(6, 2030), new Holder('A B')),
        billing: new BillingAddress('A', 'B', '1 Main St', 'Town', new Country('US'), '10001'),
        connection: new ConnectionContext('203.0.113.7', 'Mozilla/5.0'),
        phase: RiskPhase::Authorization,
        gatewayId: 'gw-123',
    );

    expect($request->gatewayId)->toBe('gw-123')
        ->and((new RiskAssessmentRequest(
            amount: Money::USD(0),
            card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(6, 2030), new Holder('A B')),
            billing: new BillingAddress('A', 'B', '1 Main St', 'Town', new Country('US'), '10001'),
            connection: new ConnectionContext('203.0.113.7', 'Mozilla/5.0'),
            phase: RiskPhase::Registration,
        ))->gatewayId)->toBeNull();
});

it('exposes the two risk phases', function () {
    expect(RiskPhase::Registration->value)->toBe('registration')
        ->and(RiskPhase::Authorization->value)->toBe('authorization')
        ->and(RiskPhase::cases())->toHaveCount(2);
});
