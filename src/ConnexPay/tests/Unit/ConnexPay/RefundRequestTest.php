<?php

declare(strict_types=1);

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\RefundRequest;

function refundBadResponse(int $status, array $body): BadResponseException
{
    return new BadResponseException(
        'Client error',
        new GuzzleRequest('POST', '/api/v1/returns'),
        new GuzzleResponse($status, [], json_encode($body)),
    );
}

it('builds refund data with DeviceGuid, SaleGuid and Amount', function () {
    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => 'sale-guid-xyz',
        'deviceGuid' => 'device-456',
    ]);

    $data = $request->getData();

    expect($data['DeviceGuid'])->toBe('device-456')
        ->and($data['SaleGuid'])->toBe('sale-guid-xyz')
        ->and($data['Amount'])->toBe(25.00)
        ->and($data)->not->toHaveKey('OrderNumber');
});

it('forwards clientUniqueId as OrderNumber', function () {
    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => 'sale-guid-xyz',
        'deviceGuid' => 'device-456',
        'clientUniqueId' => 'ORD-42',
    ]);

    expect($request->getData()['OrderNumber'])->toBe('ORD-42');
});

it('falls back to void when the sale has not been settled', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/returns', Mockery::any())
        ->andThrow(refundBadResponse(422, ['message' => 'Sale has not been settled']));
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/void', Mockery::on(
            fn (array $data): bool => $data['SaleGuid'] === 'sale-guid-xyz' && $data['Amount'] === 25.00,
        ))
        ->andReturn(['wasProcessed' => true, 'guid' => 'void-guid-1', 'status' => 'Transaction - Approved']);

    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => 'sale-guid-xyz',
        'deviceGuid' => 'device-456',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('void-guid-1');
});

it('does not fall back to void on other 422 errors', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/returns', Mockery::any())
        ->andThrow(refundBadResponse(422, ['message' => 'Amount exceeds the sale amount']));

    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => 'sale-guid-xyz',
        'deviceGuid' => 'device-456',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeFalse();
});
