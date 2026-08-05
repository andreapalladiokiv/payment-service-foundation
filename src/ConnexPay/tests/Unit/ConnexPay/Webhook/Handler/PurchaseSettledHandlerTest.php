<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\Webhook\Handler\PurchaseSettledHandler;
use Techork\PaymentService\ConnexPay\Webhook\ServiceFeeFetcher;
use Techork\PaymentService\Gateway\Contract\VirtualCardReferenceRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

function purchaseSettledEvent(array $overrides = []): ArrayObject
{
    return new ArrayObject(array_replace([
        'eventType' => 'purchase.card.auth.settled',
        'cardGuid' => 'vc-cardGuid-1',
    ], $overrides));
}

it('records VC fee when fetcher returns it and reference resolves', function () {
    $gatewayId = GatewayId::generate();
    $virtualCardId = '01942f6e-1c3a-7b8d-9e4f-111111111111';
    $fee = new Money(150, new Currency('USD'));

    $vcRepo = Mockery::mock(VirtualCardReferenceRepository::class);
    $vcRepo->shouldReceive('findVirtualCardId')
        ->once()
        ->with($gatewayId, 'vc-cardGuid-1')
        ->andReturn($virtualCardId);

    $fetcher = Mockery::mock(ServiceFeeFetcher::class);
    $fetcher->shouldReceive('fetchPurchaseFee')->once()->with($gatewayId, 'vc-cardGuid-1')->andReturn($fee);

    $recorder = Mockery::mock(GatewayFeeRecorder::class);
    $recorder->shouldReceive('onVirtualCardFee')
        ->once()
        ->withArgs(function (GatewayId $gid, string $vcId, Money $f) use ($gatewayId, $virtualCardId, $fee) {
            return $gid->equals($gatewayId) && $vcId === $virtualCardId && $f === $fee;
        })
        ->andReturn(RecorderOutcome::Applied);

    expect(new PurchaseSettledHandler($vcRepo, $recorder, $fetcher)(purchaseSettledEvent(), $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('returns Delay when VC reference unknown locally (webhook re-delivery will catch)', function () {
    $vcRepo = Mockery::mock(VirtualCardReferenceRepository::class);
    $vcRepo->shouldReceive('findVirtualCardId')->andReturnNull();

    $handler = new PurchaseSettledHandler(
        $vcRepo,
        Mockery::mock(GatewayFeeRecorder::class),
        Mockery::mock(ServiceFeeFetcher::class),
    );

    expect($handler(purchaseSettledEvent(), GatewayId::generate()))->toBe(HandlerOutcome::Delay);
});

it('returns Skipped when cardGuid is absent', function () {
    $handler = new PurchaseSettledHandler(
        Mockery::mock(VirtualCardReferenceRepository::class),
        Mockery::mock(GatewayFeeRecorder::class),
        Mockery::mock(ServiceFeeFetcher::class),
    );

    expect($handler(purchaseSettledEvent(['cardGuid' => '']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Skipped when fee is not yet available', function () {
    $vcRepo = Mockery::mock(VirtualCardReferenceRepository::class);
    $vcRepo->shouldReceive('findVirtualCardId')->andReturn('01942f6e-1c3a-7b8d-9e4f-222222222222');

    $fetcher = Mockery::mock(ServiceFeeFetcher::class);
    $fetcher->shouldReceive('fetchPurchaseFee')->andReturnNull();

    $recorder = Mockery::mock(GatewayFeeRecorder::class);
    $recorder->shouldNotReceive('onVirtualCardFee');

    $handler = new PurchaseSettledHandler($vcRepo, $recorder, $fetcher);

    expect($handler(purchaseSettledEvent(), GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});
