<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\ConnexPay\VoidResponse;

/**
 * Real ConnexPay sandbox response for POST /api/v1/Void of an AuthOnly,
 * captured on 2026-05-06. ConnexPay nests the original auth under "authOnly"
 * but keeps wasProcessed/guid/status at the top level — so no envelope unwrap
 * is needed for the success path.
 */
it('marks an approved Void response as successful', function () {
    $response = new VoidResponse(Mockery::mock(RequestInterface::class), [
        'guid' => '8c9a88ba-e297-49bc-a0cc-8a1c337acc25',
        'status' => 'Transaction - Approved',
        'wasProcessed' => true,
        'amount' => 3.0,
        'authOnlyGuid' => '9265e8b3-6464-48d4-87dd-4a30300412e8',
        'authOnly' => [
            'guid' => '9265e8b3-6464-48d4-87dd-4a30300412e8',
            'status' => 'Transaction - Approved',
        ],
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('8c9a88ba-e297-49bc-a0cc-8a1c337acc25');
});
