<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\UpdateVirtualCardRequest;
use Techork\PaymentService\ConnexPay\UpdateVirtualCardResponse;

it('builds the update body with formatted AmountLimit and zero-padded PurchaseType', function () {
    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'card-guid-xyz',
        'money' => new Money(2500, new Currency('USD')),
        'spendCategory' => 'travel_generic',
    ]);

    expect($request->getData())->toBe([
        'AmountLimit' => 25.0,
        'PurchaseType' => '06',
    ]);
});

it('throws when transactionReference is missing', function () {
    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1000, new Currency('USD')),
        'spendCategory' => 'travel_air',
    ]);

    $request->getData();
})->throws(InvalidRequestException::class);

it('throws when money is missing', function () {
    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'card-guid-xyz',
        'spendCategory' => 'travel_air',
    ]);

    $request->getData();
})->throws(InvalidRequestException::class);

it('throws when spendCategory is missing', function () {
    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'card-guid-xyz',
        'money' => new Money(1000, new Currency('USD')),
    ]);

    $request->getData();
})->throws(InvalidRequestException::class);

it('sends PUT to /api/v1/IssueCard/{cardGuid} and preserves the cardGuid in the response', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('put')
        ->once()
        ->with('/api/v1/IssueCard/card-guid-xyz', [
            'AmountLimit' => 50.0,
            'PurchaseType' => '01',
        ])
        ->andReturn([]);

    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'card-guid-xyz',
        'money' => new Money(5000, new Currency('USD')),
        'spendCategory' => 'travel_air',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response)->toBeInstanceOf(UpdateVirtualCardResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('card-guid-xyz');
});

it('returns failed response on HTTP error', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('put')
        ->once()
        ->andThrow(new TransferException('Network error'));

    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'card-guid-xyz',
        'money' => new Money(1000, new Currency('USD')),
        'spendCategory' => 'travel_air',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Network error')
        ->and($response->getTransactionReference())->toBe('card-guid-xyz');
});
