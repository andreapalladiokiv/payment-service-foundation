<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\ConnexPay\TerminateCardResponse;

it('marks success when terminated flag is true', function () {
    $response = new TerminateCardResponse(Mockery::mock(RequestInterface::class), [
        'terminated' => true,
        'cardGuid' => 'card-guid-xyz',
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('card-guid-xyz');
});

it('marks failure when terminated flag is false', function () {
    $response = new TerminateCardResponse(Mockery::mock(RequestInterface::class), [
        'terminated' => false,
        'cardGuid' => 'card-guid-xyz',
        'message' => 'Network unreachable',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Network unreachable')
        ->and($response->getTransactionReference())->toBe('card-guid-xyz');
});
