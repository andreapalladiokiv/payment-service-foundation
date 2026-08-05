<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Mockery\MockInterface;
use Omnipay\Common\Http\PsrClient;
use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\ConnexPay\CaptureRequest;
use Techork\PaymentService\ConnexPay\CaptureResponse;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;

/**
 * Payload below is the literal sale envelope embedded in a real
 * /api/v1/Captures response captured from sandbox on 2026-05-06.
 * The envelope is what CaptureRequest::sendData unwraps before constructing
 * the response.
 */
function captureSaleEnvelopePayload(): array
{
    return [
        'guid' => 'f3a4ea0b-a36b-4c04-83c9-e030428a325c',
        'status' => 'Transaction - Approved',
        'amount' => 5.0,
        'tenderType' => 'Credit',
        'wasProcessed' => true,
        'processorStatusCode' => 'A0000',
        'processorResponseMessage' => 'Success',
        'card' => [
            'first6' => '401200',
            'last4' => '5439',
            'cardType' => 'Visa',
            'expirationDate' => '2030-12',
            'guid' => '44d491d4-2c23-4493-9d95-eb7301c0afda',
        ],
        'addressVerificationCode' => '0',
        'cvvVerificationCode' => 'M',
    ];
}

it('returns the sale guid (not the capture guid) as transaction reference', function () {
    $response = new CaptureResponse(Mockery::mock(RequestInterface::class), captureSaleEnvelopePayload());

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('f3a4ea0b-a36b-4c04-83c9-e030428a325c')
        ->and($response->getMessage())->toBe('Success');
});

it('exposes AVS and CVV from the sale envelope', function () {
    $response = new CaptureResponse(Mockery::mock(RequestInterface::class), captureSaleEnvelopePayload());

    expect($response->getCvcCheck())->toBe(CheckResult::Pass)
        ->and($response->getAddressLineCheck())->toBe(CheckResult::Unchecked);
});

it('unwraps the sale envelope from the raw HTTP response', function () {
    /** @var ConnexPayHttpClientInterface&MockInterface $client */
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/Captures', Mockery::any())
        ->andReturn([
            'guid' => '8c999755-908f-498b-b4b6-c17895a2f1c5',
            'deviceGuid' => 'd4d1267d-d619-4704-86cd-a9c6c3c1ec2c',
            'sale' => captureSaleEnvelopePayload(),
        ]);

    $request = new CaptureRequest(new PsrClient, new \Symfony\Component\HttpFoundation\Request);
    $request->initialize([
        'transactionReference' => 'auth-guid-abc',
        'deviceGuid' => 'd4d1267d-d619-4704-86cd-a9c6c3c1ec2c',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response)->toBeInstanceOf(CaptureResponse::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('f3a4ea0b-a36b-4c04-83c9-e030428a325c');
});

it('returns a not-successful response on Guzzle error', function () {
    /** @var ConnexPayHttpClientInterface&MockInterface $client */
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->andThrow(new TransferException('Network error'));

    $request = new CaptureRequest(new PsrClient, new \Symfony\Component\HttpFoundation\Request);
    $request->initialize([
        'transactionReference' => 'auth-guid-abc',
        'deviceGuid' => 'd4d1267d-d619-4704-86cd-a9c6c3c1ec2c',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Network error');
});
