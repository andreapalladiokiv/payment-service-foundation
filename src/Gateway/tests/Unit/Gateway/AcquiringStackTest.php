<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\Gateway as GatewayContract;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Decorator\FailureBoundary;
use Techork\PaymentService\Gateway\Decorator\LoggingGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\GatewayFactory;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\Role\AcquiringGateway;
use Techork\PaymentService\Gateway\Routing\RoutedGateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;

/**
 * The operations that run through the proxy + decorator stack rather than through a method on the
 * router. What used to be a 40-line router method per operation, each mixing four concerns, is
 * four objects shared by all of them, and each is asserted on its own.
 *
 * Capture carries the detailed cases because the pieces are shared; the sweep at the bottom is
 * what checks that every migrated operation actually goes through them rather than round.
 */
function captureCommand(?Money $amount = null): CaptureCommand
{
    return new CaptureCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'auth_ref',
        amount: $amount ?? new Money(1000, new Currency('USD')),
        clientUniqueId: 'pi-1:capture',
    );
}

function captureStackDriver(callable $answer): AcquiringGateway
{
    $driver = Mockery::mock(AcquiringGateway::class);
    $driver->shouldReceive('capture')->andReturnUsing($answer);

    return $driver;
}

/*
 * Eleven tests of `ResultAssembler::outcome()` and `::authorization()` lived here: a provider
 * response was folded into a result by asking it `isSuccessful()`, `getTransactionReference()`
 * and a handful of capability interfaces — ChallengeProvider, CardChecksProvider,
 * TransactionMetadataProvider, ConvertedAmountProvider. Every one of those is gone with the
 * response objects that implemented them. A provider maps its own answer now, so the cases moved
 * to where the mapping is: PaymentIntentOutcome (Stripe), NuveiTransactionOutcome,
 * MapsConnexPayOutcome — including the `opening_transaction_reference` split, which each of the
 * three pins for itself. What is still shared, and still tested below, is the boundary, the
 * logging and the proxy.
 */

// ────────────────────────────── what a command says about a series

/**
 * A series payment carries a POSITION, which is the whole reason it is its own command: the
 * anchor is the command's to hold, so an ordinary placement cannot claim a series by accident and
 * a series payment cannot forget to. Null is a statement rather than an omission — inside a
 * series it means THIS payment opens it, and `RebillingCreateAdapter` passes it explicitly for
 * that reason.
 *
 * Asserted on the command's own fields. It used to be asserted on `toParameters()`, the array
 * that carried `rebilling` and `rebillingReference` into a request class Omnipay made the two
 * operations share; there are no shared request classes and no parameter arrays, and the one
 * provider that still needs the anchor reads `genesisReference` directly
 * ({@see \Techork\PaymentService\Nuvei\Concern\PaymentBody}).
 */
it('holds the series anchor on the command, and an absent one as absent', function () {
    $anchored = new RebillingCommand(
        gatewayId: GatewayId::generate(),
        instrument: Mockery::mock(PaymentInstrument::class),
        amount: new Money(1000, new Currency('USD')),
        initiation: PaymentInitiation::MerchantRecurring,
        genesisReference: 'genesis_1',
    );

    $opening = new RebillingCommand(
        gatewayId: GatewayId::generate(),
        instrument: Mockery::mock(PaymentInstrument::class),
        amount: new Money(1000, new Currency('USD')),
        initiation: PaymentInitiation::CardholderInitiated,
    );

    expect($anchored->genesisReference)->toBe('genesis_1')
        ->and($opening->genesisReference)->toBeNull()
        // A placement has nowhere to put one, which is the guarantee.
        ->and(property_exists(PlacementCommand::class, 'genesisReference'))->toBeFalse();
});

/**
 * Log contexts ride along with every gateway call the LoggingGateway makes, so a command that
 * dumped its ThreeDSResult whole put the cryptogram — the one-time bearer credential the
 * liability shift is claimed with — into every request log. The projection on the value object
 * is what keeps the tail only; this pins the two commands that carry a 3DS result.
 */
it('logs the 3DS result masked, never the cryptogram in the clear', function () {
    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-bearer-credential-4321',
        ECICode::VisaSuccessful,
        'ds-txn-123',
        'acs-txn-456',
    );

    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $placement = new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: $instrument,
        amount: new Money(1000, new Currency('USD')),
        threeDS: $threeDS,
    );

    $rebilling = new RebillingCommand(
        gatewayId: GatewayId::generate(),
        instrument: $instrument,
        amount: new Money(1000, new Currency('USD')),
        initiation: PaymentInitiation::CardholderInitiated,
        threeDS: $threeDS,
    );

    expect($placement->toLogContext()['threeDS']['authentication_value'])->toBe('…4321')
        ->and($rebilling->toLogContext()['threeDS']['authentication_value'])->toBe('…4321')
        ->and(json_encode($placement->toLogContext()))->not->toContain('cavv-bearer-credential-4321');
});

// ────────────────────────────── FailureBoundary

it('turns a thrown provider error into a failed result', function () {
    $boundary = new FailureBoundary(captureStackDriver(
        fn () => throw new RuntimeException('connection reset'),
    ));

    expect($boundary->capture(captureCommand()))
        ->success->toBeFalse()
        ->message->toBe('connection reset');
});

/**
 * The one thing that must NOT become a value. A marked refusal means we asked a gateway for
 * something its product does not have; folding it would record an acquirer decline for a payment
 * no acquirer ever saw.
 */
it('rethrows a marked refusal instead of folding it into a decline', function () {
    $boundary = new FailureBoundary(captureStackDriver(
        fn () => throw UnsupportedOperation::forGateway('paynet', 'capture', 'no auth-only product'),
    ));

    // Caught rather than `toThrow`: the marker is an interface, and Pest reads a non-Throwable
    // class-string as an expected message.
    $thrown = null;

    try {
        $boundary->capture(captureCommand());
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class)
        ->and($thrown)->toBeInstanceOf(UnsupportedOperation::class);
});


// ────────────────────────────── LoggingGateway

it('logs the request from the command and the answer from the result', function () {
    $lines = [];
    $logger = Mockery::mock(GatewayLoggerInterface::class);
    $logger->shouldReceive('log')->andReturnUsing(function (string $message, array $context) use (&$lines): void {
        $lines[$message] = $context;
    });

    $logging = new LoggingGateway(
        captureStackDriver(fn () => GatewayResult::succeeded('cap_1')),
        $logger,
        'connexpay',
    );

    $logging->capture(captureCommand());

    expect($lines)->toHaveKeys(['Gateway capture request', 'Gateway capture response'])
        ->and($lines['Gateway capture request'])
        ->toHaveKey('gatewayName', 'connexpay')
        ->toHaveKey('transactionReference', 'auth_ref')
        ->and($lines['Gateway capture response'])
        ->toHaveKey('reference', 'cap_1')
        ->toHaveKey('success', true);
});

// ────────────────────────────── RoutedGateway

function routedCaptureGateway(GatewayCredentialRepository $credentials, ?GatewayContract $driver = null): RoutedGateway
{
    $factory = Mockery::mock(GatewayFactory::class);
    $factory->shouldReceive('createForCredential')->andReturn($driver ?? Mockery::mock(GatewayContract::class));

    return new RoutedGateway(GatewayId::generate(), $credentials, $factory);
}

function routedCaptureCredentials(): GatewayCredentialRepository
{
    $credential = Mockery::mock(GatewayCredential::class);
    $credential->shouldReceive('getGatewayName')->andReturn('stripe');

    $repository = Mockery::mock(GatewayCredentialRepository::class);
    $repository->shouldReceive('findOrFail')->once()->andReturn($credential);

    return $repository;
}

it('resolves the provider once however many calls it proxies', function () {
    $driver = Mockery::mock(GatewayContract::class);
    $driver->shouldReceive('capture')->twice()->andReturn(GatewayResult::succeeded('cap_1'));

    // `findOrFail` is mocked `->once()`; a second lookup fails the expectation.
    $gateway = routedCaptureGateway(routedCaptureCredentials(), $driver);

    $gateway->capture(captureCommand());
    $gateway->capture(captureCommand());
});

it('never resolves a credential for a payment that is never captured', function () {
    $repository = Mockery::mock(GatewayCredentialRepository::class);
    $repository->shouldReceive('findOrFail')->never();

    routedCaptureGateway($repository);

    expect(true)->toBeTrue();
});

/**
 * The reason the boundary is composed INSIDE the resolve rather than around it. An unknown
 * credential is a wiring error; wrapped the other way it would be caught and recorded as an
 * acquirer decline for a gateway nobody could even find.
 */
it('lets an unknown credential propagate instead of folding it into a decline', function () {
    $repository = Mockery::mock(GatewayCredentialRepository::class);
    $repository->shouldReceive('findOrFail')->andThrow(new RuntimeException('no such credential'));

    expect(fn () => routedCaptureGateway($repository)->capture(captureCommand()))
        ->toThrow(RuntimeException::class, 'no such credential');
});

// ────────────────────────────── every migrated operation, through the same two decorators

/**
 * The decorators exist so a cross-cutting concern is written once. That only holds if every
 * operation goes through them, and an operation added to a role without a line in
 * {@see FailureBoundary} or {@see LoggingGateway} would compile — the interface would fail, but a
 * delegation that forgets to log would not. This is what notices.
 */
it('bounds and logs every operation the acquiring stack carries', function (string $operation) {
    $lines = [];
    $logger = Mockery::mock(GatewayLoggerInterface::class);
    $logger->shouldReceive('log')->andReturnUsing(function (string $message, array $context) use (&$lines): void {
        $lines[] = $message;
    });

    $driver = Mockery::mock(AcquiringGateway::class);
    $driver->shouldReceive($operation)->andThrow(new RuntimeException('connection reset'));

    $stack = new LoggingGateway(new FailureBoundary($driver), $logger, 'test');

    $command = match ($operation) {
        'capture' => captureCommand(),
        'cancel' => new CancelCommand(GatewayId::generate(), 'auth_ref', 'pi-1:cancel'),
        'registerCustomer' => new RegisterCustomerCommand(
            gatewayId: GatewayId::generate(),
            customer: gatewaySuiteCustomer(),
        ),
        default => new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'sale_ref',
            amount: new Money(1000, new Currency('USD')),
            clientUniqueId: 'refund-1',
        ),
    };

    $result = $stack->{$operation}($command);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('connection reset')
        ->and($lines)->toBe(["Gateway {$operation} request", "Gateway {$operation} response"]);
})->with(['capture', 'cancel', 'refund', 'retryRefund', 'registerCustomer']);

/**
 * Registering a customer at a provider that cannot make one from an identity alone must propagate,
 * not decline.
 *
 * `UnsupportedByGateway` is what makes the difference, and this is the operation where getting it
 * wrong reads worst: folded into a failed result it would say a provider refused a customer, when
 * the provider was never told about one and has no route that could have been asked. ConnexPay is
 * the case — it HAS a customer object, built by `/api/v1/verify`, and no endpoint that creates one
 * without a card.
 */
it('rethrows a refusal to register a customer instead of reporting a decline', function () {
    $driver = Mockery::mock(AcquiringGateway::class);
    $driver->shouldReceive('registerCustomer')->andThrow(UnsupportedOperation::forGateway(
        'connexpay',
        'registerCustomer',
        'no endpoint creates a customer without a card.',
    ));

    $stack = new LoggingGateway(new FailureBoundary($driver), Mockery::mock(GatewayLoggerInterface::class, ['log' => null]), 'test');

    // Caught rather than `toThrow`: the marker is an interface, and Pest reads a non-Throwable
    // class-string as an expected message.
    $thrown = null;

    try {
        $stack->registerCustomer(new RegisterCustomerCommand(
            gatewayId: GatewayId::generate(),
            customer: gatewaySuiteCustomer(),
        ));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class);
});

/**
 * A payment carries the customer, and the commands are where that is guaranteed.
 *
 * Asserted structurally and on every command at once, because the failure this catches is a field
 * added to one command and forgotten on another — which is invisible until a whole class of
 * payment reaches a provider anonymous. `authorizeRebilling` is the one that had no customer at
 * all while routing through the very call that reads the key, so a renewal could not use the
 * stored instrument it existed to charge.
 *
 * `customer` rather than `customerId`, and the whole customer is what closes a second hole the
 * same shape: the id, the identity and the address were three separately-omissible fields, so a
 * command could name a customer and still leave the provider to guess who they were off the
 * address. There is one field to forget now instead of three.
 */
it('gives every command a place to name the customer', function (string $class) {
    expect(property_exists($class, 'customer'))->toBeTrue()
        ->and(property_exists($class, 'customerId'))->toBeFalse()
        ->and(property_exists($class, 'billingAddress'))->toBeFalse();
})->with([
    PlacementCommand::class,
    RebillingCommand::class,
    CaptureCommand::class,
    RefundCommand::class,
    VaultCommand::class,
    RegisterCustomerCommand::class,
]);

/**
 * And a series payment keeps it when it degrades to an ordinary placement — the conversion Stripe
 * takes, which drops the anchor deliberately and must not drop the payer with it.
 */
it('carries the customer through a series payment seen as a placement', function () {
    $series = new RebillingCommand(
        gatewayId: GatewayId::generate(),
        instrument: Mockery::mock(PaymentInstrument::class),
        amount: new Money(1000, new Currency('USD')),
        initiation: PaymentInitiation::MerchantRecurring,
        customer: gatewaySuiteCustomer(),
    );

    expect($series->toPlacement()->customer)->toBe($series->customer);
});
