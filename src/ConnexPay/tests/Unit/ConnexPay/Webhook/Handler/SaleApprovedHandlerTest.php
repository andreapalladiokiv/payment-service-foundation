<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\Webhook\Handler\SaleApprovedHandler;
use Techork\PaymentService\ConnexPay\Webhook\ServiceFeeFetcher;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

function saleApprovedEvent(array $overrides = []): ArrayObject
{
    return new ArrayObject(array_replace([
        'eventType' => 'sale.card.auth.approved',
        'guid' => 'sale-guid-7',
    ], $overrides));
}

it('returns Skipped when guid is missing', function () {
    $handler = new SaleApprovedHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewayFeeRecorder::class),
        Mockery::mock(ServiceFeeFetcher::class),
    );

    expect($handler(saleApprovedEvent(['guid' => '']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Delay when our PaymentIntent is not yet observed', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $handler = new SaleApprovedHandler(
        $resolver,
        Mockery::mock(GatewayFeeRecorder::class),
        Mockery::mock(ServiceFeeFetcher::class),
    );

    expect($handler(saleApprovedEvent(), GatewayId::generate()))->toBe(HandlerOutcome::Delay);
});

it('returns Skipped when the fee fetcher returns null (fee not yet booked)', function () {
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn('01942f6e-1c3a-7b8d-9e4f-aaaaaaaaaaaa');

    $fetcher = Mockery::mock(ServiceFeeFetcher::class);
    $fetcher->shouldReceive('fetchSaleFee')->once()->with($gatewayId, 'sale-guid-7')->andReturnNull();

    $recorder = Mockery::mock(GatewayFeeRecorder::class);
    $recorder->shouldNotReceive('onPaymentIntentFee');

    $handler = new SaleApprovedHandler($resolver, $recorder, $fetcher);

    expect($handler(saleApprovedEvent(), $gatewayId))->toBe(HandlerOutcome::Skipped);
});

it('forwards the fetched fee to FeeRecorder when present', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-bbbbbbbbbbbb';
    $fee = new Money(35, new Currency('USD'));

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn($piId);

    $fetcher = Mockery::mock(ServiceFeeFetcher::class);
    $fetcher->shouldReceive('fetchSaleFee')->andReturn($fee);

    $recorder = Mockery::mock(GatewayFeeRecorder::class);
    $recorder->shouldReceive('onPaymentIntentFee')
        ->once()
        ->withArgs(function (GatewayId $gid, string $pi, Money $f) use ($gatewayId, $piId, $fee) {
            return $gid->equals($gatewayId) && $pi === $piId && $f === $fee;
        })
        ->andReturn(RecorderOutcome::Applied);

    $handler = new SaleApprovedHandler($resolver, $recorder, $fetcher);

    expect($handler(saleApprovedEvent(), $gatewayId))->toBe(HandlerOutcome::Processed);
});
