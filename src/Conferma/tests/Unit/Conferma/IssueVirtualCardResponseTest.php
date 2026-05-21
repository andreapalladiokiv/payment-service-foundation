<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Conferma\IssueVirtualCardRequest;
use Techork\PaymentService\Conferma\IssueVirtualCardResponse;

function responseFor(array $data): IssueVirtualCardResponse
{
    $request = new IssueVirtualCardRequest(new OmnipayClient, new HttpRequest);

    return new IssueVirtualCardResponse($request, $data);
}

it('parses a successful Conferma deployment response', function () {
    $response = responseFor([
        'deploymentId' => 'dep_1',
        'consumerReference' => 'pi_42',
        'status' => 'Deployed',
        'cardDetails' => [
            'number' => '4111111111111111',
            'cvv' => '123',
            'expiryYear' => 2030,
            'expiryMonth' => 12,
        ],
        'deploymentAmountDetails' => [
            'deploymentAmount' => ['currency' => 'GBP'],
        ],
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('dep_1');

    $result = $response->toVirtualCardResult();

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('dep_1')
        ->and($result->cardNumber)->toBe('4111111111111111')
        ->and($result->cvv)->toBe('123')
        ->and($result->expirationDate)->toBe('12/30')
        ->and($result->status)->toBe('Deployed');
});

it('treats PreDeployment as a successful (still-pending) issuance', function () {
    $response = responseFor([
        'deploymentId' => 'dep_2',
        'status' => 'PreDeployment',
    ]);

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->toVirtualCardResult()->success)->toBeTrue()
        ->and($response->toVirtualCardResult()->status)->toBe('PreDeployment');
});

it('treats Failed status as unsuccessful', function () {
    $response = responseFor([
        'deploymentId' => 'dep_3',
        'status' => 'Failed',
        'message' => 'pool empty',
    ]);

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->toVirtualCardResult()->success)->toBeFalse()
        ->and($response->toVirtualCardResult()->message)->toBe('pool empty');
});

it('returns failed result when deploymentId is absent', function () {
    $result = responseFor(['error' => 'unauthorized'])->toVirtualCardResult();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('unauthorized');
});

it('zero-pads single-digit months and trims year to two digits', function () {
    $response = responseFor([
        'deploymentId' => 'dep_4',
        'status' => 'Deployed',
        'cardDetails' => [
            'number' => '4111111111111111',
            'cvv' => '999',
            'expiryYear' => 2027,
            'expiryMonth' => 3,
        ],
    ]);

    expect($response->toVirtualCardResult()->expirationDate)->toBe('03/27');
});

it('returns null expirationDate when year or month is missing', function () {
    $response = responseFor([
        'deploymentId' => 'dep_5',
        'status' => 'Deployed',
        'cardDetails' => ['number' => '4111111111111111'],
    ]);

    expect($response->toVirtualCardResult()->expirationDate)->toBeNull();
});

it('falls back to id when deploymentId is absent (defensive)', function () {
    $response = responseFor(['id' => 'fallback_id', 'status' => 'Deployed']);

    expect($response->getTransactionReference())->toBe('fallback_id');
});
