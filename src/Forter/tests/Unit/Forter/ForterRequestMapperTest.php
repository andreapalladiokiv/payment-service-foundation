<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\Risk\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\Risk\FraudScreeningRequest;
use Techork\PaymentService\Forter\ForterRequestMapper;

it('maps the PCI-safe card, billing and connection onto the Forter payload', function () {
    $payload = (new ForterRequestMapper)->toOrderPayload(makeForterScreeningRequest());

    expect($payload['orderId'])->toBe('fraud-ref-1')
        ->and($payload['orderType'])->toBe('WEB')
        ->and($payload['authorizationStep'])->toBe('PRE_AUTHORIZATION')
        ->and($payload['connectionInformation'])->toBe(['customerIP' => '203.0.113.7', 'userAgent' => 'Mozilla/5.0'])
        ->and($payload['payment'][0]['creditCard'])->toBe([
            'nameOnCard' => 'John Doe',
            'bin' => '411111',
            'lastFourDigits' => '1111',
            'expirationMonth' => '06',
            'expirationYear' => '2030',
        ])
        ->and($payload['payment'][0]['billingDetails']['address']['country'])->toBe('US')
        ->and($payload['accountOwner']['email'])->toBe('john@example.com');
});

it('formats the amount as a decimal string under amountUSD', function () {
    $payload = (new ForterRequestMapper)->toOrderPayload(makeForterScreeningRequest(amountMinorUnits: 12345));

    expect($payload['totalAmount'])->toBe(['amountUSD' => '123.45'])
        ->and($payload['payment'][0]['amount'])->toBe(['amountUSD' => '123.45']);
});

it('never emits raw PAN or CVV', function () {
    $payload = (new ForterRequestMapper)->toOrderPayload(makeForterScreeningRequest());

    $json = json_encode($payload);

    expect($json)->not->toContain('cvv')
        ->and($json)->not->toContain('cvc')
        ->and($payload['payment'][0]['creditCard'])->not->toHaveKey('number');
});

it('includes the device token as forterTokenCookie when present, omits it otherwise', function () {
    $without = (new ForterRequestMapper)->toOrderPayload(makeForterScreeningRequest());
    expect($without['connectionInformation'])->not->toHaveKey('forterTokenCookie');

    $request = new FraudScreeningRequest(
        reference: 'ref-3',
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(1, 2031), new Holder('A B')),
        billing: new BillingAddress('A', 'B', '1 Main St', 'Town', new Country('US'), '10001'),
        amountMinorUnits: 1000,
        currencyCode: 'USD',
        connection: new ConnectionContext('203.0.113.7', 'UA', deviceToken: 'forter-device-xyz'),
    );

    expect((new ForterRequestMapper)->toOrderPayload($request)['connectionInformation']['forterTokenCookie'])->toBe('forter-device-xyz');
});

it('omits optional billing fields that are absent', function () {
    $request = new FraudScreeningRequest(
        reference: 'ref-2',
        card: new CardSummary('511111', '2222', CardBrand::Mastercard, Expiration::fromMonthAndYear(1, 2031), new Holder('A B')),
        billing: new BillingAddress('A', 'B', '2 Side St', 'Town', new Country('GB'), 'EC1A 1BB'),
        amountMinorUnits: 1000,
        currencyCode: 'GBP',
        connection: new ConnectionContext('198.51.100.9', 'UA'),
    );

    $payload = (new ForterRequestMapper)->toOrderPayload($request);

    expect($payload['payment'][0]['billingDetails']['address'])->not->toHaveKey('region')
        ->and($payload['payment'][0]['billingDetails'])->not->toHaveKey('phone')
        ->and($payload['accountOwner'])->not->toHaveKey('email');
});
