<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Conferma\ConfermaHttpClientInterface;
use Techork\PaymentService\Conferma\UpdateVirtualCardRequest;
use Techork\PaymentService\Conferma\UpdateVirtualCardResponse;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;

it('builds an update body with spendType and deploymentAmount', function () {
    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'confermaClient' => Mockery::mock(ConfermaHttpClientInterface::class),
        'transactionReference' => 'dep_42',
        'money' => new Money(20000, new Currency('USD')),
        'spendCategory' => CardSpendCategory::TravelAir->value,
    ]);

    expect($request->getData())->toBe([
        'spendType' => 'Air',
        'deploymentAmount' => ['value' => 200.0, 'currency' => 'USD'],
    ]);
});

it('sends PUT to /deployments/v1/deployments/{id}', function () {
    $client = Mockery::mock(ConfermaHttpClientInterface::class);
    $client->shouldReceive('put')
        ->once()
        ->with('/deployments/v1/deployments/dep_42', Mockery::on(fn ($body) => $body['spendType'] === 'Air'))
        ->andReturn(['status' => 'Updated']);

    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'confermaClient' => $client,
        'transactionReference' => 'dep_42',
        'money' => new Money(20000, new Currency('USD')),
        'spendCategory' => CardSpendCategory::TravelAir->value,
    ]);

    $response = $request->send();
    expect($response)->toBeInstanceOf(UpdateVirtualCardResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('dep_42');

    expect($response->toVirtualCardResult()->success)->toBeTrue();
});

it('returns failed result on HTTP error', function () {
    $client = Mockery::mock(ConfermaHttpClientInterface::class);
    $client->shouldReceive('put')->once()->andThrow(new GuzzleHttp\Exception\ConnectException(
        'network gone',
        new GuzzleHttp\Psr7\Request('PUT', '/'),
    ));

    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'confermaClient' => $client,
        'transactionReference' => 'dep_42',
        'money' => new Money(20000, new Currency('USD')),
        'spendCategory' => CardSpendCategory::TravelGeneric->value,
    ]);

    $response = $request->send();
    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toContain('network gone')
        ->and($response->toVirtualCardResult()->success)->toBeFalse();
});
