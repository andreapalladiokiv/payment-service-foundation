<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\ConnexPay\AuthorizeResponse;

/**
 * Payload below is a real ConnexPay sandbox response for
 * POST /api/v1/AuthOnlys captured on 2026-05-06 (test card 4012000098765439).
 */
function authorizeApprovedPayload(): array
{
    return [
        'guid' => '1b4ea913-8aa8-4e84-a184-9d96e9adec4e',
        'status' => 'Transaction - Approved',
        'amount' => 5.0,
        'deviceGuid' => 'd4d1267d-d619-4704-86cd-a9c6c3c1ec2c',
        'processorStatusCode' => 'A0000',
        'processorResponseMessage' => 'Success',
        'wasProcessed' => true,
        'card' => [
            'first6' => '401200',
            'last4' => '5439',
            'cardHolderName' => 'Test User',
            'cardType' => 'Visa',
            'expirationDate' => '2030-12',
            'guid' => '44d491d4-2c23-4493-9d95-eb7301c0afda',
        ],
        'addressVerificationCode' => '0',
        'cvvVerificationCode' => 'M',
    ];
}

it('marks an approved AuthOnly response as successful', function () {
    $response = new AuthorizeResponse(Mockery::mock(RequestInterface::class), authorizeApprovedPayload());

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('1b4ea913-8aa8-4e84-a184-9d96e9adec4e')
        ->and($response->getMessage())->toBe('Success');
});

it('exposes AVS and CVV from a real AuthOnly response', function () {
    $response = new AuthorizeResponse(Mockery::mock(RequestInterface::class), authorizeApprovedPayload());

    expect($response->getAddressLineCheck())->toBe(CheckResult::Unchecked)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Unchecked)
        ->and($response->getCvcCheck())->toBe(CheckResult::Pass);
});

it('marks a Guzzle-failure payload as not successful', function () {
    $response = new AuthorizeResponse(Mockery::mock(RequestInterface::class), [
        'wasProcessed' => false,
        'guid' => null,
        'processorResponseMessage' => 'Connection refused',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getTransactionReference())->toBeNull()
        ->and($response->getMessage())->toBe('Connection refused');
});
