<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Revolut\IssueVirtualCardResponse;

function makeRevolutIssueResponse(array $data): IssueVirtualCardResponse
{
    return new IssueVirtualCardResponse(Mockery::mock(RequestInterface::class), $data);
}

it('is a VirtualCardResponseInterface', function () {
    expect(makeRevolutIssueResponse([]))->toBeInstanceOf(VirtualCardResponseInterface::class);
});

it('treats a response carrying a card id as successful', function () {
    expect(makeRevolutIssueResponse(['id' => 'card-1'])->isSuccessful())->toBeTrue();
});

it('treats a response with an error as failed', function () {
    $response = makeRevolutIssueResponse(['error' => 'boom']);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('boom');
});

it('treats a response without an id as failed', function () {
    expect(makeRevolutIssueResponse(['state' => 'active'])->isSuccessful())->toBeFalse();
});

it('maps the full card object onto a VirtualCardResult', function () {
    $result = makeRevolutIssueResponse([
        'id' => 'card-1',
        'expiry' => '09/2030',
        'state' => 'active',
        'pan' => '4111111111111111',
        'cvv' => '123',
    ])->toVirtualCardResult();

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('card-1')
        ->and($result->cardNumber)->toBe('4111111111111111')
        ->and($result->cvv)->toBe('123')
        ->and($result->expirationDate)->toBe('092030')
        ->and($result->status)->toBe('active');
});

it('maps a failure onto a failed VirtualCardResult', function () {
    $result = makeRevolutIssueResponse(['error' => 'quota exceeded'])->toVirtualCardResult();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('quota exceeded');
});
