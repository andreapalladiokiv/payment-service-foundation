<?php

declare(strict_types=1);

use Techork\PaymentService\Forter\FraudScreeningProvider;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\IpAddress;
use Techork\PaymentService\Forter\FraudDecision;
use Techork\PaymentService\Forter\FraudScreeningRequest;
use Techork\PaymentService\Forter\FraudVerdict;

it('lets a fraud-screening provider be implemented and return a verdict', function () {
    $provider = new class implements FraudScreeningProvider
    {
        public function screen(FraudScreeningRequest $request): FraudVerdict
        {
            return new FraudVerdict(FraudDecision::Approve, reference: $request->reference);
        }
    };

    $request = new FraudScreeningRequest(
        reference: 'ref-1',
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(1, 2031), new Holder('A B')),
        billing: new BillingAddress('A', 'B', '1 Main St', 'Town', new Country('US'), '10001'),
        amountMinorUnits: 12345,
        currencyCode: 'USD',
        connection: new ConnectionContext(new IpAddress('203.0.113.7'), 'Mozilla/5.0'),
    );

    $verdict = $provider->screen($request);

    expect($verdict->isApproved())->toBeTrue()
        ->and($verdict->reference)->toBe('ref-1');
});
