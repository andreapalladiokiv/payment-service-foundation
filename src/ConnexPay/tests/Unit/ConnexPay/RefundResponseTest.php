<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\ConnexPay\RefundResponse;

/**
 * ConnexPay /api/v1/Returns returns a flat top-level structure (no envelope).
 * In sandbox an unsettled sale yields {"message": "...", "errorId": "..."} at
 * HTTP 422, surfaced via Guzzle exception in our flow.
 */
it('marks an approved Return response as successful', function () {
    $response = new RefundResponse(Mockery::mock(RequestInterface::class), [
        'guid' => 'return-guid-1',
        'status' => 'Transaction - Approved',
        'wasProcessed' => true,
        'amount' => 2.5,
        'processorResponseMessage' => 'Success',
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('return-guid-1');
});

it('marks a Guzzle-error payload as not successful', function () {
    $response = new RefundResponse(Mockery::mock(RequestInterface::class), [
        'wasProcessed' => false,
        'guid' => null,
        'processorResponseMessage' => 'Sale has not been settled',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Sale has not been settled');
});
