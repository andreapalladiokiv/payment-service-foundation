<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\ConnexPay\UpdateVirtualCardResponse;

/**
 * PUT /api/v1/IssueCard/{guid} doesn't echo the card payload; success is HTTP
 * 200 with empty body, failure is a body containing 'error'. UpdateVirtualCardRequest
 * preserves the cardGuid into the response data so callers can correlate.
 */
it('marks success when error is absent and exposes the preserved cardGuid', function () {
    $response = new UpdateVirtualCardResponse(Mockery::mock(RequestInterface::class), [
        'cardGuid' => 'card-guid-xyz',
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('card-guid-xyz')
        ->and($response->toVirtualCardResult()->success)->toBeTrue()
        ->and($response->toVirtualCardResult()->cardGuid)->toBe('card-guid-xyz');
});

it('marks failure when error is set', function () {
    $response = new UpdateVirtualCardResponse(Mockery::mock(RequestInterface::class), [
        'cardGuid' => 'card-guid-xyz',
        'error' => 'Network unreachable',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Network unreachable')
        ->and($response->toVirtualCardResult()->success)->toBeFalse()
        ->and($response->toVirtualCardResult()->message)->toBe('Network unreachable');
});
