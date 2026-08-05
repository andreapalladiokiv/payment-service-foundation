<?php

declare(strict_types=1);

use Techork\PaymentService\ConnexPay\Webhook\Handler\SaleVoidedHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

it('cancels the matching PaymentIntent', function () {
    $piId = '01942f6e-1c3a-7b8d-9e4f-eeeeeeeeeeee';

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn($piId);

    $recorder = Mockery::mock(GatewayCancellationRecorder::class);
    $recorder->shouldReceive('onGatewayCancellation')
        ->once()
        ->with($piId)
        ->andReturn(RecorderOutcome::Applied);

    $event = new ArrayObject(['eventType' => 'sale.card.auth.voided', 'guid' => 'sale-guid-v']);

    expect(new SaleVoidedHandler($resolver, $recorder)($event, GatewayId::generate()))
        ->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when guid is missing', function () {
    $handler = new SaleVoidedHandler(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewayCancellationRecorder::class),
    );
    $event = new ArrayObject(['eventType' => 'sale.card.auth.voided']);

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});
