<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
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
})->with(['capture', 'cancel', 'refund', 'retryRefund']);
