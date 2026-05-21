<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\EventParser;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\ParsedEvent;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;

function makeWebhookCredential(GatewayId $id, string $gatewayName, array $credentials = []): GatewayCredential
{
    return new class($id, $gatewayName, $credentials) implements GatewayCredential
    {
        public function __construct(
            private readonly GatewayId $id,
            private readonly string $gatewayName,
            private readonly array $credentials,
        ) {}

        public function getId(): GatewayId
        {
            return $this->id;
        }

        public function getGatewayName(): string
        {
            return $this->gatewayName;
        }

        public function getCredentials(): array
        {
            return $this->credentials;
        }
    };
}

function makeRequest(array $payload = []): ServerRequestInterface
{
    return (new Psr17Factory)->createServerRequest('POST', '/webhooks/webhooks')->withParsedBody($payload);
}

it('identifies the first tenant whose verifier accepts the signature', function () {
    $alphaId = GatewayId::generate();
    $betaId = GatewayId::generate();

    $repository = Mockery::mock(GatewayCredentialRepository::class);
    $repository->shouldReceive('all')->once()->andReturn([
        makeWebhookCredential($alphaId, 'Stripe', ['webhook_signing_secret' => 'alpha']),
        makeWebhookCredential($betaId, 'Stripe', ['webhook_signing_secret' => 'beta']),
    ]);

    $verifier = Mockery::mock(SignatureVerifier::class);
    $verifier->shouldReceive('verify')
        ->with(Mockery::type(ServerRequestInterface::class), Mockery::on(
            fn (GatewayCredential $credential) => $credential->getId()->toString() === $alphaId->toString(),
        ))
        ->andReturnFalse();
    $verifier->shouldReceive('verify')
        ->with(Mockery::type(ServerRequestInterface::class), Mockery::on(
            fn (GatewayCredential $credential) => $credential->getId()->toString() === $betaId->toString(),
        ))
        ->andReturnTrue();

    $parser = Mockery::mock(EventParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedEvent('payment_intent.succeeded', 'evt_123', (object) []));

    $verifiers = new VerifierRegistry;
    $verifiers->register('Stripe', $verifier, $parser);

    $router = new WebhookRouter($repository, $verifiers, new HandlerRegistry);
    $match = $router->identifyGateway(makeRequest(['id' => 'evt_123', 'type' => 'payment_intent.succeeded']));

    expect($match)->not->toBeNull()
        ->and($match->gatewayId->toString())->toBe($betaId->toString())
        ->and($match->kind)->toBe('stripe')
        ->and($match->externalId)->toBe('evt_123');
});

it('returns null when no verifier accepts', function () {
    $repository = Mockery::mock(GatewayCredentialRepository::class);
    $repository->shouldReceive('all')->once()->andReturn([
        makeWebhookCredential(GatewayId::generate(), 'Stripe', ['webhook_signing_secret' => 'x']),
    ]);

    $verifier = Mockery::mock(SignatureVerifier::class);
    $verifier->shouldReceive('verify')->andReturnFalse();

    $verifiers = new VerifierRegistry;
    $verifiers->register('Stripe', $verifier, Mockery::mock(EventParser::class));

    $router = new WebhookRouter($repository, $verifiers, new HandlerRegistry);

    expect($router->identifyGateway(makeRequest()))->toBeNull();
});

it('dispatches to the handler registered for (kind, event-type)', function () {
    $gatewayId = GatewayId::generate();
    $call = new StoredWebhookCall(
        kind: 'stripe',
        gatewayId: $gatewayId,
        payload: ['type' => 'payment_intent.succeeded'],
    );

    $native = (object) ['id' => 'pi_stub'];
    $parser = Mockery::mock(EventParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedEvent('payment_intent.succeeded', 'evt', $native));

    $handler = Mockery::mock(WebhookEventHandler::class);
    $handler->shouldReceive('__invoke')
        ->once()
        ->with($native, Mockery::on(fn (GatewayId $id) => $id->toString() === $gatewayId->toString()))
        ->andReturn(HandlerOutcome::Processed);

    $verifiers = new VerifierRegistry;
    $verifiers->register('Stripe', Mockery::mock(SignatureVerifier::class), $parser);

    $handlers = new HandlerRegistry;
    $handlers->register('Stripe', 'payment_intent.succeeded', $handler);

    $router = new WebhookRouter(
        Mockery::mock(GatewayCredentialRepository::class),
        $verifiers,
        $handlers,
    );

    expect($router->dispatch($call))->toBe(HandlerOutcome::Processed);
});

it('returns Skipped when no handler is registered for the event type', function () {
    $call = new StoredWebhookCall(
        kind: 'stripe',
        gatewayId: GatewayId::generate(),
        payload: ['type' => 'unknown.event'],
    );

    $parser = Mockery::mock(EventParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedEvent('unknown.event', 'evt', (object) []));

    $verifiers = new VerifierRegistry;
    $verifiers->register('Stripe', Mockery::mock(SignatureVerifier::class), $parser);

    $router = new WebhookRouter(
        Mockery::mock(GatewayCredentialRepository::class),
        $verifiers,
        new HandlerRegistry,
    );

    expect($router->dispatch($call))->toBe(HandlerOutcome::Skipped);
});

it('returns Skipped when the kind has no parser registered', function () {
    $call = new StoredWebhookCall(
        kind: 'unknown-gateway',
        gatewayId: GatewayId::generate(),
        payload: [],
    );

    $router = new WebhookRouter(
        Mockery::mock(GatewayCredentialRepository::class),
        new VerifierRegistry,
        new HandlerRegistry,
    );

    expect($router->dispatch($call))->toBe(HandlerOutcome::Skipped);
});
