<?php

declare(strict_types=1);

use Techork\PaymentService\ConnexPay\AuthorizeRequest;
use Techork\PaymentService\ConnexPay\CaptureRequest;
use Techork\PaymentService\ConnexPay\ConnexPayGateway;
use Techork\PaymentService\ConnexPay\CreateCardRequest;
use Techork\PaymentService\ConnexPay\CreatePaymentMethodRequest;
use Techork\PaymentService\ConnexPay\PurchaseRequest;
use Techork\PaymentService\ConnexPay\RefundRequest;
use Techork\PaymentService\ConnexPay\VoidRequest;

function makeConnexPayGateway(): ConnexPayGateway
{
    $gw = new ConnexPayGateway;
    $gw->initialize([
        'username' => 'test-user',
        'password' => 'test-pass',
        'deviceGuid' => 'device-abc',
        'environment' => 'sandbox',
    ]);

    return $gw;
}

it('has name connexpay', function () {
    expect(makeConnexPayGateway()->getName())->toBe('connexpay');
});

it('initializes with credentials', function () {
    $gw = makeConnexPayGateway();

    expect($gw->getUsername())->toBe('test-user')
        ->and($gw->getPassword())->toBe('test-pass')
        ->and($gw->getDeviceGuid())->toBe('device-abc')
        ->and($gw->getEnvironment())->toBe('sandbox');
});

it('defaults the account currency to USD when the credential is absent', function () {
    expect(makeConnexPayGateway()->getAccountCurrency())->toBe('USD');
});

it('maps the snake_case account_currency credential onto the gateway', function () {
    // The DB hands credentials over as snake_case; Omnipay's Helper translates
    // them into set*() calls during initialize().
    $gw = new ConnexPayGateway;
    $gw->initialize([
        'username' => 'test-user',
        'password' => 'test-pass',
        'device_guid' => 'device-abc',
        'account_currency' => 'CAD',
    ]);

    expect($gw->getAccountCurrency())->toBe('CAD');
});

it('propagates the account currency into every request it builds', function () {
    // createRequest merges gateway parameters into the request, which is the
    // only reason formatMoney() can see the account currency at all.
    $gw = new ConnexPayGateway;
    $gw->initialize([
        'username' => 'test-user',
        'password' => 'test-pass',
        'device_guid' => 'device-abc',
        'account_currency' => 'gbp',
    ]);

    expect($gw->purchase()->getAccountCurrency())->toBe('GBP')
        ->and($gw->authorize()->getAccountCurrency())->toBe('GBP')
        ->and($gw->refund()->getAccountCurrency())->toBe('GBP');
});

it('creates createCard request', function () {
    expect(makeConnexPayGateway()->createCard())->toBeInstanceOf(CreateCardRequest::class);
});

it('creates createPaymentMethod request', function () {
    expect(makeConnexPayGateway()->createPaymentMethod())->toBeInstanceOf(CreatePaymentMethodRequest::class);
});

it('creates purchase request', function () {
    expect(makeConnexPayGateway()->purchase())->toBeInstanceOf(PurchaseRequest::class);
});

it('creates authorize request', function () {
    expect(makeConnexPayGateway()->authorize())->toBeInstanceOf(AuthorizeRequest::class);
});

it('creates capture request', function () {
    expect(makeConnexPayGateway()->capture())->toBeInstanceOf(CaptureRequest::class);
});

it('creates refund request', function () {
    expect(makeConnexPayGateway()->refund())->toBeInstanceOf(RefundRequest::class);
});

it('creates void request', function () {
    expect(makeConnexPayGateway()->void())->toBeInstanceOf(VoidRequest::class);
});
