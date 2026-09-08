<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\ConnexPay\ConnexPayGateway;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\PartialCapture;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

function partialCaptureCommand(int $amount, ?int $authorized = null, ?PaymentInstrument $instrument = null): CaptureCommand
{
    return new CaptureCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'auth-guid-1',
        amount: new Money($amount, new Currency('USD')),
        authorizedAmount: $authorized === null ? null : new Money($authorized, new Currency('USD')),
        instrument: $instrument,
    );
}

function partialCaptureGateway(?ConnexPayHttpClientInterface $client = null): ConnexPayGateway
{
    $gateway = new ConnexPayGateway;
    $gateway->configure(new GatewayInfrastructure(
        cpCredential(),
        cpDecrypter(),
        cpInstruments('pm-guid-1'),
        Mockery::mock(Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository::class, ['find' => null]),
        ['username' => 'u', 'password' => 'p', 'deviceGuid' => 'device-1'],
    ));

    if ($client !== null) {
        $gateway->setHttpClient($client);
    }

    return $gateway;
}

function cpPartialCapture(ConnexPayHttpClientInterface $client, ?string $reference = 'pm-guid-1'): PartialCapture
{
    return new PartialCapture(
        cpSettings(),
        partialCaptureCommand(3000, 5000, cpStoredPaymentMethod()),
        cpInfrastructure(['instruments' => cpInstruments($reference)]),
        $client,
    );
}

// ──────────────────────────────────────────────
//  which provider call the gateway makes
//
//  ConnexPay is the only driver that answers one role with two different calls, so the choice is
//  the behaviour. With no accessor to hand the operation back, it is observed where it shows:
//  which endpoint the client is asked for.
// ──────────────────────────────────────────────

it('routes an equal-amount capture to the plain capture endpoint', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->with('/api/v1/Captures', Mockery::any())
        ->andReturn(['sale' => ['wasProcessed' => true, 'guid' => 'sale-guid']]);

    $gateway = partialCaptureGateway($client);

    expect($gateway->capture(partialCaptureCommand(5000, 5000, cpStoredPaymentMethod()))->reference)
        ->toBe('sale-guid');
});

it('routes a smaller-amount capture to the void-then-resell pair', function () {
    $client = cpHttpClient();
    $client->shouldNotReceive('post')->with('/api/v1/Captures', Mockery::any());
    $client->shouldReceive('post')->once()->ordered()->with('/api/v1/void', Mockery::any())
        ->andReturn(['wasProcessed' => true, 'guid' => 'void-guid']);
    $client->shouldReceive('post')->once()->ordered()->with('/api/v1/sales', Mockery::any())
        ->andReturn(['wasProcessed' => true, 'guid' => 'new-sale-guid']);

    $gateway = partialCaptureGateway($client);

    expect($gateway->capture(partialCaptureCommand(3000, 5000, cpStoredPaymentMethod()))->reference)
        ->toBe('new-sale-guid');
});

it('falls back to the plain capture endpoint when the authorized amount is unknown', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->with('/api/v1/Captures', Mockery::any())
        ->andReturn(['sale' => ['wasProcessed' => true, 'guid' => 'sale-guid']]);

    expect(partialCaptureGateway($client)->capture(partialCaptureCommand(3000))->reference)->toBe('sale-guid');
});

it('rejects a capture above the authorized amount', function () {
    partialCaptureGateway()->capture(partialCaptureCommand(6000, 5000));
})->throws(InvalidArgumentException::class, 'exceeds the authorized amount');

it('rejects a partial capture without the original instrument', function () {
    partialCaptureGateway()->capture(partialCaptureCommand(3000, 5000));
})->throws(InvalidArgumentException::class, 'without the original instrument');

// ──────────────────────────────────────────────
//  the two-step itself
// ──────────────────────────────────────────────

it('reuses the purchase payload builder for the replacement sale', function () {
    $payload = cpPartialCapture(cpHttpClient())->payload();

    expect($payload['Amount'])->toBe(30.00)
        ->and($payload['TenderType'])->toBe('Credit')
        ->and($payload['Card']['Guid'])->toBe('pm-guid-1')
        // The stored payment method's own address becomes the sale's RiskData, exactly as the
        // gateway used to copy it onto the request options.
        ->and($payload['RiskData']['BillingAddress1'])->toBe('1 St');
});

it('voids the auth and runs a fresh sale for the partial amount', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->ordered()
        ->with('/api/v1/void', Mockery::on(fn (array $d): bool => $d['AuthOnlyGuid'] === 'auth-guid-1'))
        ->andReturn(['wasProcessed' => true, 'guid' => 'void-guid']);
    $client->shouldReceive('post')
        ->once()
        ->ordered()
        ->with('/api/v1/sales', Mockery::on(
            fn (array $d): bool => $d['Amount'] === 30.00 && $d['Card']['Guid'] === 'pm-guid-1',
        ))
        ->andReturn([
            'wasProcessed' => true,
            'guid' => 'new-sale-guid',
            'status' => 'Transaction - Approved',
            'connexPayTransaction' => ['incomingTransCode' => 'ICT-77'],
        ]);

    $result = cpPartialCapture($client)->capture();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('new-sale-guid')
        ->and($result->metadata)->toBe(['incoming_transaction_code' => 'ICT-77']);
});

it('does not run the sale when the void fails', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/void', Mockery::any())
        ->andThrow(new TransferException('void exploded'));

    $result = cpPartialCapture($client)->capture();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Void before partial capture failed');
});

it('reports a failed sale after a successful void as a failed capture', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->ordered()->with('/api/v1/void', Mockery::any())
        ->andReturn(['wasProcessed' => true, 'guid' => 'void-guid']);
    $client->shouldReceive('post')->once()->ordered()->with('/api/v1/sales', Mockery::any())
        ->andReturn(['wasProcessed' => false, 'guid' => null, 'processorResponseMessage' => 'Do not honor']);

    $result = cpPartialCapture($client)->capture();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Do not honor');
});
