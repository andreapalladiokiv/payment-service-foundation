<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Conferma\ConfermaHttpClientInterface;
use Techork\PaymentService\Conferma\TerminateCardRequest;
use Techork\PaymentService\Conferma\TerminateCardResponse;

it('sends an empty POST to the cancel endpoint', function () {
    $client = Mockery::mock(ConfermaHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with('/deployments/v1/deployments/dep_42/cancel', [])
        ->andReturn(['status' => 'WithSupplierCancelled']);

    $request = new TerminateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'confermaClient' => $client,
        'transactionReference' => 'dep_42',
    ]);

    $response = $request->send();
    expect($response)->toBeInstanceOf(TerminateCardResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('dep_42');
});

it('returns a failed response on HTTP error', function () {
    $client = Mockery::mock(ConfermaHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andThrow(new GuzzleHttp\Exception\ConnectException(
        'gone',
        new GuzzleHttp\Psr7\Request('POST', '/'),
    ));

    $request = new TerminateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'confermaClient' => $client,
        'transactionReference' => 'dep_42',
    ]);

    $response = $request->send();
    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toContain('gone');
});
