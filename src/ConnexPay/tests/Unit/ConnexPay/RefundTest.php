<?php

declare(strict_types=1);

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\Refund;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function cpRefundCommand(?string $clientUniqueId = null): RefundCommand
{
    return new RefundCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'sale-guid-xyz',
        amount: new Money(2500, new Currency('USD')),
        clientUniqueId: $clientUniqueId,
    );
}

function cpRefund(?string $clientUniqueId = null, ?ConnexPayHttpClientInterface $client = null): Refund
{
    return new Refund(
        cpSettings(['deviceGuid' => 'device-456']),
        cpRefundCommand($clientUniqueId),
        $client ?? cpHttpClient(),
    );
}

/**
 * @param  array<string, mixed>  $body
 */
function cpRefundBadResponse(int $status, array $body): BadResponseException
{
    return new BadResponseException(
        'Client error',
        new GuzzleRequest('POST', '/api/v1/returns'),
        new GuzzleResponse($status, [], json_encode($body)),
    );
}

it('builds refund data with DeviceGuid, SaleGuid and Amount', function () {
    $data = cpRefund()->payload();

    expect($data['DeviceGuid'])->toBe('device-456')
        ->and($data['SaleGuid'])->toBe('sale-guid-xyz')
        ->and($data['Amount'])->toBe(25.00)
        ->and($data)->not->toHaveKey('OrderNumber');
});

it('forwards clientUniqueId as OrderNumber', function () {
    expect(cpRefund('ORD-42')->payload()['OrderNumber'])->toBe('ORD-42');
});

/**
 * ConnexPay /api/v1/Returns answers with a flat top-level structure (no envelope).
 */
it('marks an approved Return as successful', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/returns', Mockery::any())
        ->andReturn([
            'guid' => 'return-guid-1',
            'status' => 'Transaction - Approved',
            'wasProcessed' => true,
            'amount' => 2.5,
            'processorResponseMessage' => 'Success',
        ]);

    $result = cpRefund(client: $client)->refund();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('return-guid-1');
});

it('falls back to void when the sale has not been settled', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/returns', Mockery::any())
        ->andThrow(cpRefundBadResponse(422, ['message' => 'Sale has not been settled']));
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/void', Mockery::on(
            fn (array $data): bool => $data['SaleGuid'] === 'sale-guid-xyz' && $data['Amount'] === 25.00,
        ))
        ->andReturn(['wasProcessed' => true, 'guid' => 'void-guid-1', 'status' => 'Transaction - Approved']);

    $result = cpRefund(client: $client)->refund();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('void-guid-1');
});

it('does not fall back to void on other 422 errors', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/returns', Mockery::any())
        ->andThrow(cpRefundBadResponse(422, ['message' => 'Amount exceeds the sale amount']));

    expect(cpRefund(client: $client)->refund()->success)->toBeFalse();
});

it('marks a declined Return as unsuccessful and keeps the processor message', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn([
        'wasProcessed' => false,
        'guid' => null,
        'processorResponseMessage' => 'Sale has not been settled',
    ]);

    $result = cpRefund(client: $client)->refund();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Sale has not been settled');
});

it('returns a failed result on a transport error', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Network error'));

    expect(cpRefund(client: $client)->refund()->message)->toBe('Network error');
});
