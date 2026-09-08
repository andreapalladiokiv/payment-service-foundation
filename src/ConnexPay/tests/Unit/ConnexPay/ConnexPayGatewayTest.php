<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\ConnexPay\ConnexPayGateway;
use Techork\PaymentService\ConnexPay\ConnexPaySettings;
use Techork\PaymentService\ConnexPay\Refund;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * @param  array<string, mixed>  $settings
 */
function makeConnexPayGateway(array $settings = []): ConnexPayGateway
{
    $gateway = new ConnexPayGateway;
    $gateway->configure(new GatewayInfrastructure(
        cpCredential(),
        cpDecrypter(),
        cpInstruments(null),
        Mockery::mock(Techork\PaymentService\Gateway\Contract\CustomerRepository::class, ['findByInstrument' => null]),
        [
            'username' => 'test-user',
            'password' => 'test-pass',
            'deviceGuid' => 'device-abc',
            'environment' => 'sandbox',
            ...$settings,
        ],
    ));

    return $gateway;
}

function gatewayRefundCommand(): RefundCommand
{
    return new RefundCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'sale-guid',
        amount: new Money(1500, new Currency('USD')),
    );
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

/**
 * The DB hands credentials over as snake_case; {@see GatewayInfrastructure::setting()} accepts
 * either spelling so a row that was never migrated keeps loading.
 */
it('maps the snake_case account_currency credential onto the gateway', function () {
    expect(makeConnexPayGateway(['device_guid' => 'device-abc', 'account_currency' => 'CAD'])->getAccountCurrency())
        ->toBe('CAD');
});

/**
 * The settings object is the whole of what the deployment contributes, so it is what an operation
 * is built from — and it is what makes the guard reach every payload without being restated.
 */
it('carries the account currency into the settings every operation is built from', function () {
    $gw = makeConnexPayGateway(['account_currency' => 'gbp']);

    expect($gw->settings())->toBeInstanceOf(ConnexPaySettings::class)
        ->and($gw->settings()->acquiringCurrency())->toBe('GBP');

    // A USD amount on a GBP account is refused wherever it is built, which is the point of the
    // guard living on the settings rather than on each operation.
    expect(fn () => new Refund($gw->settings(), gatewayRefundCommand(), cpHttpClient())->payload())
        ->toThrow(InvalidArgumentException::class, 'provisioned in GBP but the amount is USD');
});

/**
 * ConnexPay's /authonlys has no cash tender, so an auth against cash has to run as a sale —
 * transparently, the way the legacy acquirer did it. Observable only from `authorize()`, which is
 * where the choice is made.
 */
it('routes a cash authorization through the sale endpoint', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/sales', Mockery::on(fn (array $d): bool => $d['TenderType'] === 'Cash'))
        ->andReturn(['wasProcessed' => true, 'guid' => 'cash-sale-guid']);

    $gw = makeConnexPayGateway();
    $gw->setHttpClient($client);

    expect($gw->authorize(cpPlacement(['instrument' => new Cash]))->reference)->toBe('cash-sale-guid');
});

it('sends a card authorization to the auth-only endpoint', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/authonlys', Mockery::any())
        ->andReturn(['wasProcessed' => true, 'guid' => 'auth-guid']);

    $gw = makeConnexPayGateway();
    $gw->setHttpClient($client);

    expect($gw->authorize(cpPlacement(['instrument' => cpCard()]))->reference)->toBe('auth-guid');
});

it('sends a charge to the sale endpoint', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/sales', Mockery::any())
        ->andReturn(['wasProcessed' => true, 'guid' => 'sale-guid']);

    $gw = makeConnexPayGateway();
    $gw->setHttpClient($client);

    expect($gw->charge(cpPlacement(['instrument' => cpCard()]))->reference)->toBe('sale-guid');
});

it('sends a tokenization and a registration to verify', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->twice()
        ->with('/api/v1/verify', Mockery::any())
        ->andReturn(['wasProcessed' => true, 'card' => ['guid' => 'card-guid']]);

    $gw = makeConnexPayGateway();
    $gw->setHttpClient($client);

    expect($gw->tokenize(cpVault(['instrument' => cpCard()]))->reference)->toBe('card-guid')
        ->and($gw->registerPaymentMethod(cpVault(['instrument' => cpCard()]))->reference)->toBe('card-guid');
});

it('sends a refund to returns', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/returns', Mockery::any())
        ->andReturn(['wasProcessed' => true, 'guid' => 'return-guid']);

    $gw = makeConnexPayGateway();
    $gw->setHttpClient($client);

    expect($gw->refund(gatewayRefundCommand())->reference)->toBe('return-guid');
});

it('sends a cancel to void', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/void', Mockery::any())
        ->andReturn(['wasProcessed' => true, 'guid' => 'void-guid']);

    $gw = makeConnexPayGateway();
    $gw->setHttpClient($client);

    expect($gw->cancel(new CancelCommand(GatewayId::generate(), 'auth-guid'))->reference)->toBe('void-guid');
});

/*
 * Everything that used to be asserted through a per-operation accessor is asserted through the
 * role method now, because the accessors are gone: they existed only so a test could reach a
 * `payload()` without making the call, and a test does not need them — `ConnexPaySettings` is a
 * public value object, so the operation tests build their operations directly. Two shims went with
 * them, `makeConnexPayGatewayPlacementRequest()` and `makeConnexPayGatewayVaultRequest()`, which
 * mapped an option array onto a command so a test could get at the request the gateway would have
 * sent.
 */
