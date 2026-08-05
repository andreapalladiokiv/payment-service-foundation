<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\ConnexPay\ConnexPayGateway;
use Techork\PaymentService\ConnexPay\Webhook\ConnexPayWebhookSubscriber;
use Techork\PaymentService\ConnexPay\Webhook\EventParser;
use Techork\PaymentService\ConnexPay\Webhook\Handler\PurchaseSettledHandler;
use Techork\PaymentService\ConnexPay\Webhook\Handler\SaleApprovedHandler;
use Techork\PaymentService\ConnexPay\Webhook\Handler\SaleDeclinedHandler;
use Techork\PaymentService\ConnexPay\Webhook\Handler\SaleVoidedHandler;
use Techork\PaymentService\ConnexPay\Webhook\ServiceFeeFetcher;
use Techork\PaymentService\ConnexPay\Webhook\SignatureVerifier;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\Contract\VirtualCardReferenceRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayCancellationRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFailureRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewayFeeRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;

/**
 * {@see ConnexPayWebhookSubscriber} is the only place the gateway kind, the
 * verifier, the parser and four handlers meet, and it was unexecuted. The real
 * {@see VerifierRegistry}, {@see HandlerRegistry} and {@see WebhookRouter} are
 * driven here rather than doubles, because every failure this class can have
 * lives BETWEEN the classes: a kind the registry is keyed by that the gateway
 * never reports, an event-type constant bound to the wrong handler, a
 * constructor nobody can satisfy. Mocked registries would answer whatever they
 * were asked and prove none of it.
 *
 * Only the recorder / fee-fetcher / resolver layer stays mocked — that is the
 * persistence and outbound-API boundary, and the handlers have their own tests
 * against it.
 *
 * Helpers are prefixed `connexPayWiring…`; Pest helpers are global suite-wide,
 * and `cpCredential` / `cpEncrypter` already exist in the package's Pest.php
 * (the credential here needs webhook basic-auth values that one does not carry).
 */
function connexPayWiringPassword(): string
{
    return 'pw_'.bin2hex(random_bytes(8));
}

function connexPayWiringCredential(string $password): GatewayCredential
{
    return new readonly class($password) implements GatewayCredential
    {
        public function __construct(private string $password) {}

        public function getId(): GatewayId
        {
            return GatewayId::fromString('01929fa5-0000-7000-8000-0000000000d1');
        }

        public function getGatewayName(): string
        {
            return 'connexpay';
        }

        public function getCredentials(): array
        {
            return ['username' => 'hook-user', 'password' => $this->password];
        }
    };
}

/**
 * A ConnexPay sale message. `guid` doubles as the idempotency key, and
 * `orderNumber` is the hosted-page fallback correlation SaleCorrelation reads.
 */
function connexPayWiringPayload(
    string $eventType = EventParser::TYPE_SALE_AUTH_VOIDED,
    string $guid = 'b8f1c1a0-0000-4000-8000-000000000abc',
): array {
    return [
        'eventType' => $eventType,
        'guid' => $guid,
        'orderNumber' => '01929fa5-0000-7000-8000-000000000009',
        'amount' => 25.00,
        'currency' => 'USD',
    ];
}

/** A delivery authenticated the documented way: HTTP Basic. */
function connexPayWiringRequest(array $payload, string $username, string $password): ServerRequestInterface
{
    $body = (string) json_encode($payload);
    $factory = new Psr17Factory;

    return $factory->createServerRequest('POST', 'https://merchant.example/webhooks/connexpay')
        ->withHeader('Authorization', 'Basic '.base64_encode($username.':'.$password))
        ->withBody($factory->createStream($body))
        ->withParsedBody($payload);
}

/**
 * The subscriber with all four handlers real and only the boundary mocked.
 * Constructing them is part of what is pinned: the subscriber names each by
 * concrete type, so a handler whose constructor changed shape fails here rather
 * than at container-resolution time in production.
 */
function connexPayWiringSubscriber(
    ?TransactionIdResolver $resolver = null,
    ?GatewayCancellationRecorder $cancellation = null,
): ConnexPayWebhookSubscriber {
    $resolver ??= Mockery::mock(TransactionIdResolver::class);

    return new ConnexPayWebhookSubscriber(
        new SignatureVerifier,
        new EventParser,
        new SaleApprovedHandler(
            $resolver,
            Mockery::mock(GatewayFeeRecorder::class),
            Mockery::mock(ServiceFeeFetcher::class),
        ),
        new SaleDeclinedHandler($resolver, Mockery::mock(GatewayFailureRecorder::class)),
        new SaleVoidedHandler($resolver, $cancellation ?? Mockery::mock(GatewayCancellationRecorder::class)),
        new PurchaseSettledHandler(
            Mockery::mock(VirtualCardReferenceRepository::class),
            Mockery::mock(GatewayFeeRecorder::class),
            Mockery::mock(ServiceFeeFetcher::class),
        ),
    );
}

/** Single-tenant repository, as the router's candidate iteration sees it. */
function connexPayWiringRepository(GatewayCredential $credential): GatewayCredentialRepository
{
    return new readonly class($credential) implements GatewayCredentialRepository
    {
        public function __construct(private GatewayCredential $credential) {}

        public function findOrFail(GatewayId $gatewayId): GatewayCredential
        {
            return $this->credential;
        }

        public function all(): iterable
        {
            return [$this->credential];
        }
    };
}

/** @return array{VerifierRegistry, HandlerRegistry} */
function connexPayWiringRegistries(?ConnexPayWebhookSubscriber $subscriber = null): array
{
    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;

    ($subscriber ?? connexPayWiringSubscriber())->subscribe($verifiers, $handlers);

    return [$verifiers, $handlers];
}

it('registers the verifier and parser under the kind the gateway reports', function () {
    // The router looks kinds up by GatewayCredential::getGatewayName(), which for
    // this package is ConnexPayGateway::getName() = 'connexpay', while the
    // subscriber registers the literal 'ConnexPay'. The registry lowercases both
    // ends; pinned because if the spellings stop meeting, every ConnexPay delivery
    // resolves to no verifier and is dropped.
    [$verifiers] = connexPayWiringRegistries();

    $kind = new ConnexPayGateway()->getName();

    expect($verifiers->hasKind($kind))->toBeTrue()
        ->and($verifiers->verifier($kind))->toBeInstanceOf(SignatureVerifier::class)
        ->and($verifiers->parser($kind))->toBeInstanceOf(EventParser::class);
});

it('points each ConnexPay event type at the handler written for it', function (string $eventType, string $handlerClass) {
    // Keyed on the parser's own constants, so a renamed constant fails to compile
    // this test rather than quietly registering a type nothing emits. The
    // sale-versus-purchase split is the one to read carefully: a sale is money
    // coming in from the cardholder, a purchase is the virtual card we issued
    // being settled, and they book to opposite sides.
    [, $handlers] = connexPayWiringRegistries();

    expect($handlers->resolve('connexpay', $eventType))->toBeInstanceOf($handlerClass);
})->with([
    'sale approved' => [EventParser::TYPE_SALE_AUTH_APPROVED, SaleApprovedHandler::class],
    'sale declined' => [EventParser::TYPE_SALE_AUTH_DECLINED, SaleDeclinedHandler::class],
    'sale voided' => [EventParser::TYPE_SALE_AUTH_VOIDED, SaleVoidedHandler::class],
    'purchase settled' => [EventParser::TYPE_PURCHASE_AUTH_SETTLED, PurchaseSettledHandler::class],
]);

it('registers no handler for a ConnexPay event type we do not act on', function (string $eventType) {
    // ConnexPay's sale/purchase lifecycle is wider than the four we react to.
    // Unmapped types must resolve to nothing so the router reports Skipped.
    [, $handlers] = connexPayWiringRegistries();

    expect($handlers->resolve('connexpay', $eventType))->toBeNull();
})->with([
    'sale settlement, which we take from the purchase side' => 'sale.card.auth.settled',
    'a lifecycle stage we do not subscribe to' => 'purchase.card.auth.approved',
    'no type at all' => '',
]);

it('identifies the tenant and the idempotency key from an authenticated delivery', function () {
    // End to end over the real router: basic-auth verification against the
    // credential, kind resolution, and the sale guid as the idempotency key. This
    // is the path a live delivery takes before anything is stored.
    $password = connexPayWiringPassword();
    $credential = connexPayWiringCredential($password);

    [$verifiers, $handlers] = connexPayWiringRegistries();
    $router = new WebhookRouter(connexPayWiringRepository($credential), $verifiers, $handlers);

    $match = $router->identifyGateway(connexPayWiringRequest(connexPayWiringPayload(), 'hook-user', $password));

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe('connexpay')
        ->and($match->externalId)->toBe('b8f1c1a0-0000-4000-8000-000000000abc')
        ->and($match->gatewayId->equals($credential->getId()))->toBeTrue();
});

it('identifies no tenant when the delivery carries the wrong credentials', function () {
    // The rejection has to survive the wiring: an unauthenticated delivery must
    // leave identifyGateway with null so nothing is stored under a tenant it does
    // not belong to. ConnexPay's webhook auth is a shared username/password, so a
    // wrong password is the whole of the attack surface.
    $credential = connexPayWiringCredential(connexPayWiringPassword());

    [$verifiers, $handlers] = connexPayWiringRegistries();
    $router = new WebhookRouter(connexPayWiringRepository($credential), $verifiers, $handlers);

    $forged = connexPayWiringRequest(connexPayWiringPayload(), 'hook-user', connexPayWiringPassword());

    expect($router->identifyGateway($forged))->toBeNull();
});

it('dispatches a stored void through the parser into its handler', function () {
    // The other half of the chain, from the stored record onwards: the parser hands
    // the handler an ArrayObject and the handler reads `guid` back out of it. A
    // mismatch between those two shapes would be invisible to per-class tests and
    // would leave every dashboard-initiated void unapplied.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $reference): bool => $reference === 'b8f1c1a0-0000-4000-8000-000000000abc')
        ->andReturn('01929fa5-0000-7000-8000-000000000009');

    $cancellation = Mockery::mock(GatewayCancellationRecorder::class);
    $cancellation->shouldReceive('onGatewayCancellation')->once()->andReturn(RecorderOutcome::Applied);

    [$verifiers, $handlers] = connexPayWiringRegistries(connexPayWiringSubscriber($resolver, $cancellation));

    $router = new WebhookRouter(
        connexPayWiringRepository(connexPayWiringCredential(connexPayWiringPassword())),
        $verifiers,
        $handlers,
    );

    $outcome = $router->dispatch(new StoredWebhookCall('connexpay', GatewayId::generate(), connexPayWiringPayload()));

    expect($outcome)->toBe(HandlerOutcome::Processed);
});

it('skips a stored delivery whose event type has no registered handler', function () {
    // Unmapped types must come back Skipped — neither retried forever nor run
    // through a handler meant for something else.
    [$verifiers, $handlers] = connexPayWiringRegistries();

    $router = new WebhookRouter(
        connexPayWiringRepository(connexPayWiringCredential(connexPayWiringPassword())),
        $verifiers,
        $handlers,
    );

    expect($router->dispatch(new StoredWebhookCall(
        'connexpay',
        GatewayId::generate(),
        connexPayWiringPayload('sale.card.auth.settled'),
    )))->toBe(HandlerOutcome::Skipped);
});
