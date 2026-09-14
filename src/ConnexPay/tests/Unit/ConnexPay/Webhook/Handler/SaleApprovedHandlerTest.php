<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\Webhook\Handler\SaleApprovedHandler;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

const SALE_APPROVED_PI = '01942f6e-1c3a-7b8d-9e4f-ffffffffffff';
const SALE_APPROVED_GUID = 'b8f1c1a0-0000-4000-8000-000000000abc';

/**
 * A hosted sale approval as the sale message carries it: the guid we have never
 * seen, the orderNumber we sent as `clientUniqueId`, the amount in major units
 * and no currency anywhere.
 *
 * @param  array<string, mixed>  $overrides
 */
function saleApprovedEvent(array $overrides = []): ArrayObject
{
    return new ArrayObject(array_replace([
        'eventType' => 'sale.card.auth.approved',
        'guid' => SALE_APPROVED_GUID,
        'orderNumber' => SALE_APPROVED_PI,
        'amount' => 25.00,
    ], $overrides));
}

/**
 * The credential row the acquiring currency comes off. Both spellings are
 * stored in the wild, so the key is the caller's to choose.
 *
 * @param  array<string, string>  $credentials
 */
function saleApprovedCredentials(array $credentials): GatewayCredentialRepository
{
    $credential = new readonly class($credentials) implements GatewayCredential
    {
        /** @param array<string, string> $credentials */
        public function __construct(private array $credentials) {}

        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'connexpay';
        }

        public function getCredentials(): array
        {
            return $this->credentials;
        }
    };

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

/** A handler whose recorder answers with $outcome. */
function saleApprovedHandler(
    TransactionIdResolver $resolver,
    RecorderOutcome $outcome,
    ?GatewayCredentialRepository $credentials = null,
): SaleApprovedHandler {
    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')->andReturn($outcome);

    return new SaleApprovedHandler($resolver, $recorder, $credentials ?? saleApprovedCredentials([]));
}

it('confirms a hosted sale the resolver has never seen a guid for', function () {
    // The case the whole handler exists for: the sale was created on ConnexPay's
    // page, so the guid is new to us and the orderNumber is the only thread back
    // to the intent we parked at RequiresAction.
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $reference): bool => $reference === SALE_APPROVED_GUID)
        ->andReturnNull();

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $pi, string $reference, Money $amount): bool => $gid->equals($gatewayId)
            && $pi === SALE_APPROVED_PI
            && $reference === SALE_APPROVED_GUID
            && $amount->equals(new Money(2500, new Currency('USD'))))
        ->andReturn(RecorderOutcome::Applied);

    $handler = new SaleApprovedHandler($resolver, $recorder, saleApprovedCredentials([]));

    expect($handler(saleApprovedEvent(), $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('records the sale guid as the reference when we did store one', function () {
    // A card-data sale: the API answered with the guid and the port stored it,
    // so the webhook correlates by guid and the orderNumber is not consulted.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->once()->andReturn(SALE_APPROVED_PI);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $pi, string $reference, Money $amount): bool => $pi === SALE_APPROVED_PI
            && $reference === SALE_APPROVED_GUID)
        ->andReturn(RecorderOutcome::Applied);

    $handler = new SaleApprovedHandler($resolver, $recorder, saleApprovedCredentials([]));

    $event = saleApprovedEvent(['orderNumber' => '']);

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Processed);
});

it('returns Delay when neither the guid nor the orderNumber names an intent', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $handler = saleApprovedHandler($resolver, RecorderOutcome::Applied);

    expect($handler(saleApprovedEvent(['orderNumber' => 'merchant-invoice-4711']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Delay);
});

it('returns Delay when the intent is not visible yet', function () {
    // A well-formed id we do not hold: the saga may not have persisted the
    // aggregate when the delivery lands, so this is a retry, not a drop.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturnNull();

    $handler = saleApprovedHandler($resolver, RecorderOutcome::NotFound);

    expect($handler(saleApprovedEvent(), GatewayId::generate()))->toBe(HandlerOutcome::Delay);
});

it('returns Skipped for a duplicate approval the intent has already applied', function () {
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn(SALE_APPROVED_PI);

    $handler = saleApprovedHandler($resolver, RecorderOutcome::Skipped);

    expect($handler(saleApprovedEvent(), GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

it('returns Skipped when the sale carries no guid, and touches nothing else', function () {
    // Without a guid there is no reference to record, and the orderNumber alone
    // would confirm an intent with nothing to resolve a later refund through.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldNotReceive('resolvePaymentIntent');

    $credentials = Mockery::mock(GatewayCredentialRepository::class);
    $credentials->shouldNotReceive('findOrFail');

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldNotReceive('onGatewaySuccess');

    $handler = new SaleApprovedHandler($resolver, $recorder, $credentials);

    expect($handler(saleApprovedEvent(['guid' => '']), GatewayId::generate()))->toBe(HandlerOutcome::Skipped);
});

it('takes the amount currency from the account, which is the only place it can come from', function (array $credentials, string $expected) {
    // ConnexPay's v1 API carries no currency on the request, the response or the
    // sale message, so the payload cannot say what the amount is in — the
    // account's acquiring currency is it, by construction.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn(SALE_APPROVED_PI);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $pi, string $reference, Money $amount): bool => $amount->equals(new Money(2500, new Currency($expected))))
        ->andReturn(RecorderOutcome::Applied);

    $handler = new SaleApprovedHandler($resolver, $recorder, saleApprovedCredentials($credentials));

    expect($handler(saleApprovedEvent(), GatewayId::generate()))->toBe(HandlerOutcome::Processed);
})->with([
    'no account currency configured, which is USD' => [[], 'USD'],
    'the camelCase spelling' => [['accountCurrency' => 'CAD'], 'CAD'],
    'the snake_case spelling, as stored rows hold it' => [['account_currency' => 'gbp'], 'GBP'],
]);

it('returns Delay rather than dropping a sale whose amount is unusable', function (mixed $amount) {
    // Not Skipped on purpose: a skip marks the delivery resolved and leaves a
    // paid intent parked forever, which is the defect this handler fixes. A
    // retry costs nothing and a body that never improves ends as a failed call,
    // which is visible.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn(SALE_APPROVED_PI);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldNotReceive('onGatewaySuccess');

    $handler = new SaleApprovedHandler($resolver, $recorder, saleApprovedCredentials([]));

    $event = saleApprovedEvent();
    $amount === null ? $event->offsetUnset('amount') : $event->offsetSet('amount', $amount);

    expect($handler($event, GatewayId::generate()))->toBe(HandlerOutcome::Delay);
})->with([
    'no amount at all' => null,
    'an empty amount' => '',
    'an amount that is not a number' => 'twenty five',
]);
