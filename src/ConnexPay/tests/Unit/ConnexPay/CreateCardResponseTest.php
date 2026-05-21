<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\ConnexPay\CreateCardResponse;
use Techork\PaymentService\Gateway\Contract\CustomerReferenceProvider;

/**
 * The shape below is what CreateCardRequest::sendData produces after reshaping
 * the raw /api/v1/Verify response. The mapping decisions are based on a real
 * sandbox call on 2026-05-06: card.guid → guid, card.customer.guid → customerGuid.
 */
it('exposes the card guid as transaction reference', function () {
    $response = new CreateCardResponse(Mockery::mock(RequestInterface::class), [
        'wasProcessed' => true,
        'guid' => '6b028ba0-cec1-433a-bb25-d738113d2472',
        'customerGuid' => null,
        'status' => 'Transaction - Approved',
        'processorResponseMessage' => null,
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('6b028ba0-cec1-433a-bb25-d738113d2472');
});

it('exposes the customer guid when billing was sent', function () {
    $response = new CreateCardResponse(Mockery::mock(RequestInterface::class), [
        'wasProcessed' => true,
        'guid' => '98f9b6e9-a0c8-4da2-b762-25798632e490',
        'customerGuid' => 'babae5fa-7bd3-45eb-bfc5-84b36eebcf3d',
        'status' => 'Transaction - Approved',
    ]);

    expect($response)->toBeInstanceOf(CustomerReferenceProvider::class)
        ->and($response->getCustomerReference())->toBe('babae5fa-7bd3-45eb-bfc5-84b36eebcf3d');
});

it('returns null customer reference when billing was not sent', function () {
    $response = new CreateCardResponse(Mockery::mock(RequestInterface::class), [
        'wasProcessed' => true,
        'guid' => 'some-card-guid',
        'customerGuid' => null,
    ]);

    expect($response->getCustomerReference())->toBeNull();
});

it('marks failure when wasProcessed is false', function () {
    $response = new CreateCardResponse(Mockery::mock(RequestInterface::class), [
        'wasProcessed' => false,
        'guid' => null,
        'customerGuid' => null,
        'processorResponseMessage' => 'Connection refused',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Connection refused');
});
