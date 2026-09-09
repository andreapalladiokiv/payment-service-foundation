<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\ReturnRetry;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;

/**
 * @param  array<string, mixed>  $wiring
 */
function cpReturnRetry(?PaymentInstrument $retryInstrument, ?string $clientUniqueId = null, array $wiring = []): ReturnRetry
{
    return new ReturnRetry(
        cpSettings(['deviceGuid' => 'device-456']),
        new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'sale-guid-xyz',
            amount: new Money(2500, new Currency('USD')),
            clientUniqueId: $clientUniqueId,
            retryInstrument: $retryInstrument,
        ),
        cpInfrastructure(['instruments' => cpInstruments($wiring['reference'] ?? null)]),
        $wiring['client'] ?? cpHttpClient(),
    );
}

it('builds retry-return data with ReturnRetryCard for a raw credit card', function () {
    $data = cpReturnRetry(cpCard(holder: 'Alt Holder'))->payload();

    expect($data['DeviceGuid'])->toBe('device-456')
        ->and($data['SaleGuid'])->toBe('sale-guid-xyz')
        ->and($data['Amount'])->toBe(25.00)
        ->and($data['ReturnRetryCard']['CardNumber'])->toBe('4012000098765439')
        ->and($data['ReturnRetryCard']['ExpirationDate'])->toBe('3012')
        ->and($data['ReturnRetryCard']['Cvv2'])->toBe('999')
        ->and($data['ReturnRetryCard']['CardHolderName'])->toBe('Alt Holder')
        ->and($data)->not->toHaveKey('OrderNumber');
});

it('resolves ReturnRetryCard.Guid from the reference resolver when given a stored Token', function () {
    $data = cpReturnRetry(cpStoredToken(), wiring: ['reference' => 'cnx-tok-ref-7'])->payload();

    expect($data['ReturnRetryCard'])->toBe(['Guid' => 'cnx-tok-ref-7']);
});

it('forwards clientUniqueId as OrderNumber', function () {
    $data = cpReturnRetry(cpCard(cvv: null, holder: 'Alt Holder'), 'refund-retry-9')->payload();

    expect($data['OrderNumber'])->toBe('refund-retry-9');
});

/**
 * The alternative card is optional on the command — a plain refund carries none — so a retry
 * built without one has to say so rather than let a null reach the visitor.
 */
it('refuses to build a retry with no alternative card', function () {
    cpReturnRetry(null)->payload();
})->throws(InvalidArgumentException::class, 'without the alternative card');

it('sends the retry and reports the returned guid', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/returns', Mockery::on(fn (array $d): bool => isset($d['ReturnRetryCard'])))
        ->andReturn(['wasProcessed' => true, 'guid' => 'retry-guid-1', 'status' => 'Transaction - Approved']);

    $result = cpReturnRetry(cpCard(), wiring: ['client' => $client])->retry();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('retry-guid-1');
});

it('returns a failed result on a transport error', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Network error'));

    expect(cpReturnRetry(cpCard(), wiring: ['client' => $client])->retry()->message)->toBe('Network error');
});
// ─────────────────────────────────────────────────────────
//  A stored card charged to nobody
//
//  Attached is a STATE of a payment method rather than a second type, so "payable" is no longer
//  something a signature carries — each payment mapper checks it. That is the trade the shape
//  made, and this is its price: the refusal is asserted at every operation that makes it, because
//  a check one mapper forgets is a card charged to whoever it happened to be billed to, which is
//  the behaviour the customer split exists to end.
// ─────────────────────────────────────────────────────────

it('refuses to retry a refund onto a stored card nobody has claimed', function () {
    expect(fn () => cpReturnRetry(cpStoredPaymentMethod())->payload())
        ->toThrow(UnsupportedInstrument::class, 'names no customer on the "retryRefund" operation');
});
