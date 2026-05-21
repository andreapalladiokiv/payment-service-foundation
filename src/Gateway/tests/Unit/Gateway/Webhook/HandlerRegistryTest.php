<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function makeHandler(): WebhookEventHandler
{
    return new class implements WebhookEventHandler
    {
        public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
        {
            return HandlerOutcome::Processed;
        }
    };
}

it('resolves a registered handler', function () {
    $registry = new HandlerRegistry;
    $handler = makeHandler();

    $registry->register('Stripe', 'payment_intent.succeeded', $handler);

    expect($registry->resolve('stripe', 'payment_intent.succeeded'))->toBe($handler);
});

it('is case-insensitive on the kind', function () {
    $registry = new HandlerRegistry;
    $handler = makeHandler();

    $registry->register('STRIPE', 'evt.x', $handler);

    expect($registry->resolve('stripe', 'evt.x'))->toBe($handler)
        ->and($registry->resolve('Stripe', 'evt.x'))->toBe($handler);
});

it('returns null for an unknown kind or event type', function () {
    $registry = new HandlerRegistry;
    $registry->register('Stripe', 'evt.x', makeHandler());

    expect($registry->resolve('nuvei', 'evt.x'))->toBeNull()
        ->and($registry->resolve('stripe', 'evt.y'))->toBeNull();
});
