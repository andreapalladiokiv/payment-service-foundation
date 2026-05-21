<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\ConnexPay\IssueVirtualCardResponse;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;

/**
 * Payload below is a real ConnexPay sandbox response for
 * POST /api/v1/IssueCard captured on 2026-05-06.
 *
 * Note: ConnexPay returns BOTH `expirationDate` (ISO datetime) and
 * `expiration` (MMYY string). We use the MMYY format because the
 * downstream `card_expiration` column is char(4).
 */
function issueCardSuccessPayload(): array
{
    return [
        'card' => [
            'cardGuid' => 'c6303e46-55a4-43b4-b50d-c7993f0d7c4f',
            'accountNumber' => '5190754485992771',
            'securityCode' => '366',
            'amountLimit' => 5.0,
            'expirationDate' => '2029-05-06T00:00:00Z',
            'expiration' => '0529',
            'currencyCode' => 'USD',
            'firstSix' => '519075',
            'lastFour' => '2771',
            'status' => 'Card - Active',
            'cardClass' => 'CommercialCredit',
        ],
        'cardBrand' => 'MasterCard',
        'saleGuid' => 'f3a4ea0b-a36b-4c04-83c9-e030428a325c',
        'incomingTransactionCode' => '5406E46639136547414675607',
    ];
}

it('marks an issued virtual card as successful', function () {
    $response = new IssueVirtualCardResponse(Mockery::mock(RequestInterface::class), issueCardSuccessPayload());

    expect($response)->toBeInstanceOf(VirtualCardResponseInterface::class)
        ->and($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('c6303e46-55a4-43b4-b50d-c7993f0d7c4f');
});

it('maps to a VirtualCardResult with MMYY expiration', function () {
    $result = (new IssueVirtualCardResponse(Mockery::mock(RequestInterface::class), issueCardSuccessPayload()))
        ->toVirtualCardResult();

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('c6303e46-55a4-43b4-b50d-c7993f0d7c4f')
        ->and($result->cardNumber)->toBe('5190754485992771')
        ->and($result->cvv)->toBe('366')
        ->and($result->expirationDate)->toBe('0529')
        ->and($result->status)->toBe('Card - Active');
});

it('marks a missing cardGuid as failure', function () {
    $response = new IssueVirtualCardResponse(Mockery::mock(RequestInterface::class), [
        'cardGuid' => null,
        'status' => 'Some HTTP error',
    ]);

    expect($response->isSuccessful())->toBeFalse();

    $result = $response->toVirtualCardResult();
    expect($result->success)->toBeFalse();
});

it('treats an unparseable error envelope as failure with json fallback', function () {
    $response = new IssueVirtualCardResponse(Mockery::mock(RequestInterface::class), [
        'unexpected' => 'shape',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->toVirtualCardResult()->success)->toBeFalse();
});
