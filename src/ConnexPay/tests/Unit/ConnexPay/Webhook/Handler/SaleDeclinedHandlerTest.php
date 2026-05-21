<?php

declare(strict_types=1);

use Techork\PaymentService\ConnexPay\Webhook\Handler\SaleDeclinedHandler;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

it('records the failure with the gateway-provided message', function () {
    $gatewayId = GatewayId::generate();
    $piId = '01942f6e-1c3a-7b8d-9e4f-cccccccccccc';

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn($piId);

    $recorder = Mockery::mock(GatewayFailureRecorder::class);
    $recorder->shouldReceive('onGatewayFailure')
        ->once()
        ->with($piId, 'Insufficient funds')
        ->andReturn(RecorderOutcome::Applied);

    $event = new ArrayObject([
        'eventType' => 'sale.card.auth.declined',
        'guid' => 'sale-guid-d',
        'processorMessage' => 'Insufficient funds',
    ]);

    expect((new SaleDeclinedHandler($resolver, $recorder))($event, $gatewayId))
        ->toBe(HandlerOutcome::Processed);
});

it('falls back to a generic reason when none provided', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn('01942f6e-1c3a-7b8d-9e4f-dddddddddddd');

    $recorder = Mockery::mock(GatewayFailureRecorder::class);
    $recorder->shouldReceive('onGatewayFailure')
        ->once()
        ->with(Mockery::any(), 'Sale declined at gateway')
        ->andReturn(RecorderOutcome::Applied);

    $event = new ArrayObject(['eventType' => 'sale.card.auth.declined', 'guid' => 'sale-guid-d2']);

    expect((new SaleDeclinedHandler($resolver, $recorder))($event, GatewayId::generate()))
        ->toBe(HandlerOutcome::Processed);
});

it('returns Delay when PI cannot be resolved', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $event = new ArrayObject(['eventType' => 'sale.card.auth.declined', 'guid' => 'sale-guid-d3']);

    $handler = new SaleDeclinedHandler($resolver, Mockery::mock(GatewayFailureRecorder::class));
    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Delay);
});
