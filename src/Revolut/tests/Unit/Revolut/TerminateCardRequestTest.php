<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;
use Techork\PaymentService\Revolut\TerminateCardRequest;

/**
 * @param  array<string, mixed>  $params
 */
function revolutTerminateRequest(array $params = [], ?RevolutHttpClientInterface $client = null): TerminateCardRequest
{
    $request = new TerminateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'revolutClient' => $client ?? Mockery::mock(RevolutHttpClientInterface::class),
        'transactionReference' => 'card-1',
        ...$params,
    ]);

    return $request;
}

it('sends no body and requires the card reference', function () {
    expect(revolutTerminateRequest()->getData())->toBe([]);
});

it('requires the card reference', function () {
    $request = new TerminateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize(['revolutClient' => Mockery::mock(RevolutHttpClientInterface::class)]);

    $request->getData();
})->throws(InvalidRequestException::class);

it('deletes the card and reports it terminated', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('delete')->once()->with('/api/1.0/cards/card-1')->andReturn([]);

    $result = revolutTerminateRequest([], $client)->send()->toVirtualCardResult();

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('card-1')
        ->and($result->status)->toBe('terminated');
});

it('reports a failed result when termination fails', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('delete')->once()->andThrow(new TransferException('not found'));

    $result = revolutTerminateRequest([], $client)->send()->toVirtualCardResult();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('not found');
});
