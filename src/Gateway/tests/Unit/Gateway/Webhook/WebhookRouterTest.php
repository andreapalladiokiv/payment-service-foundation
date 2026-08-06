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
use Psr\Http\Message\StreamInterface;
use Techork\PaymentService\Gateway\Webhook\Contract\InboundWebhook;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;

function makeWebhookCredential(GatewayId $id, string $gatewayName, array $credentials = []): GatewayCredential
{
    return new readonly class($id, $gatewayName, $credentials) implements GatewayCredential
    {
        public function __construct(
            private GatewayId $id,
            private string    $gatewayName,
            private array     $credentials,
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
        ->with(Mockery::type(InboundWebhook::class), Mockery::on(
            fn (GatewayCredential $credential) => $credential->getId()->toString() === $alphaId->toString(),
        ))
        ->andReturnFalse();
    $verifier->shouldReceive('verify')
        ->with(Mockery::type(InboundWebhook::class), Mockery::on(
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

it('lets a later candidate see the same body the first one read', function () {
    // The bug this file had no test for, and the reason it lived long enough to reach production:
    // verifiers took the PSR-7 request, the loop offered ONE request object to every candidate,
    // and the first verifier to read the body left the stream at EOF. Every candidate after it
    // checked its signature against an empty string — so in any install with more than one
    // credential of a kind, only the first could ever authenticate, and the rest looked exactly
    // like a wrong secret.
    //
    // The verifier below is written the way the real ones are, and the way the broken one was:
    // it reads the body and compares. What makes this pass is that there is no body to read,
    // only a value to look at.
    $firstId = GatewayId::generate();
    $secondId = GatewayId::generate();

    $repository = Mockery::mock(GatewayCredentialRepository::class);
    $repository->shouldReceive('all')->once()->andReturn([
        makeWebhookCredential($firstId, 'Stripe', ['webhook_signing_secret' => 'first']),
        makeWebhookCredential($secondId, 'Stripe', ['webhook_signing_secret' => 'second']),
    ]);

    $verifier = new class implements SignatureVerifier
    {
        public function verify(InboundWebhook $webhook, GatewayCredential $gateway): bool
        {
            return $webhook->body !== ''
                && ($gateway->getCredentials()['webhook_signing_secret'] ?? null) === 'second';
        }
    };

    $parser = Mockery::mock(EventParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedEvent('payment_intent.succeeded', 'evt_123', (object) []));

    $verifiers = new VerifierRegistry;
    $verifiers->register('Stripe', $verifier, $parser);

    $request = makeRequest(['id' => 'evt_123', 'type' => 'payment_intent.succeeded'])
        ->withBody((new Psr17Factory)->createStream('{"id":"evt_123","type":"payment_intent.succeeded"}'));

    $match = new WebhookRouter($repository, $verifiers, new HandlerRegistry)->identifyGateway($request);

    expect($match)->not->toBeNull()
        ->and($match->gatewayId->toString())->toBe($secondId->toString());
});

it('reads the request body exactly once, however many candidates it takes', function () {
    // The property underneath the test above, stated so a future change to identifyGateway cannot
    // quietly reintroduce a per-candidate read that a seekable test stream would then hide. A
    // non-seekable body is what production most likely delivers, and it is the shape the old
    // `isSeekable()`-guarded rewind silently did nothing for.
    $reads = 0;
    $body = new class($reads) implements StreamInterface
    {
        public function __construct(private int &$reads) {}

        public function __toString(): string
        {
            $this->reads++;

            return '{"id":"evt_123","type":"payment_intent.succeeded"}';
        }

        public function isSeekable(): bool { return false; }
        public function rewind(): void { throw new RuntimeException('not seekable'); }
        public function seek($offset, $whence = SEEK_SET): void { throw new RuntimeException('not seekable'); }
        public function getContents(): string { return (string) $this; }
        public function close(): void {}
        public function detach() { return null; }
        public function getSize(): ?int { return null; }
        public function tell(): int { return 0; }
        public function eof(): bool { return true; }
        public function write($string): int { return 0; }
        public function isWritable(): bool { return false; }
        public function isReadable(): bool { return true; }
        public function read($length): string { return ''; }
        public function getMetadata($key = null) { return null; }
    };

    $repository = Mockery::mock(GatewayCredentialRepository::class);
    $repository->shouldReceive('all')->once()->andReturn([
        makeWebhookCredential(GatewayId::generate(), 'Stripe', ['webhook_signing_secret' => 'a']),
        makeWebhookCredential(GatewayId::generate(), 'Stripe', ['webhook_signing_secret' => 'b']),
        makeWebhookCredential(GatewayId::generate(), 'Stripe', ['webhook_signing_secret' => 'c']),
    ]);

    $verifier = Mockery::mock(SignatureVerifier::class);
    $verifier->shouldReceive('verify')->andReturnFalse();

    $verifiers = new VerifierRegistry;
    $verifiers->register('Stripe', $verifier, Mockery::mock(EventParser::class));

    new WebhookRouter($repository, $verifiers, new HandlerRegistry)
        ->identifyGateway(makeRequest()->withBody($body));

    expect($reads)->toBe(1);
});
