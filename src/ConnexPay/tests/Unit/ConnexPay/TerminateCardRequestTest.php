<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\TerminateCardRequest;
use Techork\PaymentService\ConnexPay\TerminateCardResponse;

it('builds terminate data with empty body', function () {
    $request = new TerminateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['transactionReference' => 'card-guid-xyz']);

    expect($request->getData())->toBe([]);
});

it('throws when transactionReference is missing', function () {
    $request = new TerminateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize();

    $request->getData();
})->throws(InvalidRequestException::class);

it('sends POST to /api/v1/TerminateCard/{cardGuid}', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/TerminateCard/card-guid-xyz', [])
        ->andReturn(['terminateDate' => '2026-04-21']);

    $request = new TerminateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'card-guid-xyz',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response)->toBeInstanceOf(TerminateCardResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('card-guid-xyz');
});

it('returns failed response on HTTP error', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->andThrow(new TransferException('Network error'));

    $request = new TerminateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'card-guid-xyz',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Network error');
});
