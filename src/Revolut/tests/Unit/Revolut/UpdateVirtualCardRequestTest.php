<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;
use Techork\PaymentService\Revolut\UpdateVirtualCardRequest;

/**
 * @param  array<string, mixed>  $params
 */
function revolutUpdateRequest(array $params = [], ?RevolutHttpClientInterface $client = null): UpdateVirtualCardRequest
{
    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'revolutClient' => $client ?? Mockery::mock(RevolutHttpClientInterface::class),
        'transactionReference' => 'card-1',
        'money' => new Money(50000, new Currency('GBP')),
        ...$params,
    ]);

    return $request;
}

it('builds the patch body with the updated spend limit', function () {
    expect(revolutUpdateRequest()->getData()['spending_limits'])
        ->toBe(['single' => ['amount' => 500.00, 'currency' => 'GBP']]);
});

it('includes categories when a mappable spend category is supplied', function () {
    expect(revolutUpdateRequest(['spendCategory' => 'restaurants'])->getData()['categories'])->toBe(['restaurants']);
});

it('requires money and the card reference', function () {
    $request = new UpdateVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'revolutClient' => Mockery::mock(RevolutHttpClientInterface::class),
        'money' => new Money(100, new Currency('GBP')),
    ]);

    $request->getData();
})->throws(InvalidRequestException::class);

it('patches the card and maps the updated state', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('patch')
        ->once()
        ->with('/api/1.0/cards/card-1', Mockery::on(fn (array $d): bool => isset($d['spending_limits'])))
        ->andReturn(['id' => 'card-1', 'state' => 'active', 'expiry' => '09/2030']);

    $result = revolutUpdateRequest([], $client)->send()->toVirtualCardResult();

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('card-1')
        ->and($result->status)->toBe('active');
});

it('falls back to the requested card id when the patch body is sparse', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('patch')->once()->andReturn([]);

    expect(revolutUpdateRequest([], $client)->send()->toVirtualCardResult()->cardGuid)->toBe('card-1');
});

it('reports a failed result when the patch fails', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('patch')->once()->andThrow(new TransferException('card terminated'));

    $result = revolutUpdateRequest([], $client)->send()->toVirtualCardResult();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('card terminated');
});
