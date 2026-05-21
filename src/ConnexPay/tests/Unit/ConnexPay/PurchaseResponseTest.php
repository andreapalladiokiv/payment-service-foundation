<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\ConnexPay\PurchaseResponse;

/**
 * Payload below is a real ConnexPay sandbox response for
 * POST /api/v1/Sales captured on 2026-05-06.
 */
function purchaseApprovedPayload(): array
{
    return [
        'guid' => 'b321a79e-1c9d-43b9-8c68-be306c14234c',
        'status' => 'Transaction - Approved',
        'amount' => 7.5,
        'tenderType' => 'Credit',
        'wasProcessed' => true,
        'processorStatusCode' => 'A0000',
        'processorResponseMessage' => 'Success',
        'card' => [
            'first6' => '401200',
            'last4' => '5439',
            'cardType' => 'Visa',
            'guid' => 'f704f90c-9ba5-41e3-96c0-ebc5eb7a7932',
        ],
        'addressVerificationCode' => '0',
        'cvvVerificationCode' => 'M',
    ];
}

it('marks an approved Sale response as successful', function () {
    $response = new PurchaseResponse(Mockery::mock(RequestInterface::class), purchaseApprovedPayload());

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('b321a79e-1c9d-43b9-8c68-be306c14234c');
});

it('exposes AVS and CVV from a real Sale response', function () {
    $response = new PurchaseResponse(Mockery::mock(RequestInterface::class), purchaseApprovedPayload());

    expect($response->getCvcCheck())->toBe(CheckResult::Pass)
        ->and($response->getAddressLineCheck())->toBe(CheckResult::Unchecked);
});
