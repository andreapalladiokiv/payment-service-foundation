<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\CardIntelligenceProvider;
use Techork\PaymentService\Common\Contract\FraudScreeningProvider;
use Techork\PaymentService\Common\Contract\IpIntelligenceProvider;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\Risk\CardFunding;
use Techork\PaymentService\Common\ValueObject\Risk\CardIntelligence;
use Techork\PaymentService\Common\ValueObject\Risk\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\Risk\IpAddress;
use Techork\PaymentService\Common\ValueObject\Risk\FraudDecision;
use Techork\PaymentService\Common\ValueObject\Risk\FraudScreeningRequest;
use Techork\PaymentService\Common\ValueObject\Risk\FraudVerdict;
use Techork\PaymentService\Common\ValueObject\Risk\IpIntelligence;

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

it('lets a card-intelligence provider be implemented and return BIN facts', function () {
    $provider = new class implements CardIntelligenceProvider
    {
        public function lookupBin(string $bin, ?string $ip = null): ?CardIntelligence
        {
            return new CardIntelligence(new Country('GB'), CardFunding::Credit, isPrepaid: false, isCommercial: true);
        }
    };

    $intel = $provider->lookupBin('411111');

    expect((string) $intel->issuerCountry)->toBe('GB')
        ->and($intel->funding)->toBe(CardFunding::Credit)
        ->and($intel->isCommercial)->toBeTrue();
});

it('lets an ip-intelligence provider be implemented and return geo facts', function () {
    $provider = new class implements IpIntelligenceProvider
    {
        public function lookupIp(string $ip): ?IpIntelligence
        {
            return new IpIntelligence(new Country('DE'), isProxy: true);
        }
    };

    $intel = $provider->lookupIp('203.0.113.7');

    expect((string) $intel->country)->toBe('DE')
        ->and($intel->isProxy)->toBeTrue();
});
