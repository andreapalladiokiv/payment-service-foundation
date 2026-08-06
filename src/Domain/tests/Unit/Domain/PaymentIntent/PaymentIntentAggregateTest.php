<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\FailureCode;
use Techork\PaymentService\Domain\PaymentIntent\Command\CancelPaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\Command\CapturePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\Command\CreatePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\Command\RecordPaymentIntentFeeCommand;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentAuthorized;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentCancelled;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentCaptured;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentCharged;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentFailed;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentFeeRecorded;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentImported;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentRequiresAction;
use Techork\PaymentService\Domain\PaymentIntent\Exception\ChallengeCannotBeRaised;
use Techork\PaymentService\Domain\PaymentIntent\Exception\InvalidPaymentIntent;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentCannotBeCancelled;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentCannotBeCaptured;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentCannotBeRefunded;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentChallengeNotPending;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentRefundExceedsAmount;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\Port\CancelPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\CaptureOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\ConfirmChallengeOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\ConfirmChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreateOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CancelRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\ConfirmChallengeRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Command\CreateRefundCommand;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Command\RecordRefundFeeCommand;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundFailed;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundFeeRecorded;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundImported;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundProcessed;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Exception\InvalidRefund;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Exception\RefundNotFound;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\RefundPort;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\RefundStatus;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Tests\Support\PaymentIntentAggregateTestCase;
use Techork\PaymentService\Tests\Support\StubChallengePort;
use Techork\PaymentService\Tests\Support\StubPaymentIntentFirewall;
use function EventSauce\EventSourcing\PestTooling\given;
use function EventSauce\EventSourcing\PestTooling\then;

uses(PaymentIntentAggregateTestCase::class);

// ──────────────────────────────────────────────
//  Domain helpers
// ──────────────────────────────────────────────

function makeAmount(): Money
{
    return new Money(1000, new Currency('USD'));
}

function makeCreditCardForPI(): CreditCard
{
    return new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );
}

function makeInstrument(): PaymentInstrument
{
    static $instance = null;

    return $instance ??= new Token(
        TokenId::fromString('01961f5a-0000-7000-8000-000000000001'),
        makeCreditCardForPI(),
        ExpiresAt::fromString(new DateTimeImmutable('+1 hour')->format(DateTimeInterface::ATOM)),
    );
}

function makeHostedPaymentForPI(): HostedPayment
{
    return new HostedPayment('https://shop.test/paid', 'https://shop.test/cancelled');
}

function makeUnusableInstrument(): PaymentInstrument
{
    static $instance = null;

    return $instance ??= new Token(
        TokenId::fromString('01961f5a-0000-7000-8000-000000000002'),
        makeCreditCardForPI(),
        ExpiresAt::fromString(new DateTimeImmutable('-1 hour')->format(DateTimeInterface::ATOM)),
    );
}

function makeImportedPaymentMethod(string $id = '01961f5a-0000-7000-8000-000000000099'): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::fromString($id),
        makeCreditCardForPI(),
        makeBillingAddress(),
    );
}

function makeBillingAddress(): BillingAddress
{
    return new BillingAddress(firstName: 'Test', lastName: 'User', line: '123 Main St', city: 'NYC', country: new Country('US'), postalCode: '10001');
}

function makeBillingAddressFull(): BillingAddress
{
    return new BillingAddress(
        firstName: 'Test',
        lastName: 'User',
        line: '123 Main St',
        city: 'NYC',
        country: new Country('US'),
        postalCode: '10001',
        lineExtra: 'Apt 4B',
        state: new State('NY'),
        email: new Email('test@example.com'),
    );
}

function makePiThreeDSResult(ThreeDSStatus $status = ThreeDSStatus::Successful): ThreeDSResult
{
    return new ThreeDSResult(
        $status,
        'cavv-base64',
        ECICode::VisaSuccessful,
        '11111111-1111-1111-1111-111111111111',
        '22222222-2222-2222-2222-222222222222',
        ThreeDSVersion::V220,
    );
}

function makeThreeDSChallenge(): ThreeDSChallenge
{
    return new ThreeDSChallenge(
        authenticationId: 'gw-txn-123',
        url: 'https://acs.example.com/challenge',
        payload: 'base64-creq',
    );
}

function makeRedirectChallenge(): RedirectChallenge
{
    return new RedirectChallenge(
        transactionId: 'pay-77',
        url: 'https://hosted.example/checkout',
        formFields: ['operation' => 'pay-77', 'signature' => 'sig-abc'],
    );
}

// ──────────────────────────────────────────────
//  Command stubs
// ──────────────────────────────────────────────

function makeMerchantDescriptor(string $descriptor = 'ACME STORE'): MerchantDescriptor
{
    return new MerchantDescriptor($descriptor);
}

function makeCreatePiCommand(
    PaymentIntentId $id,
    CaptureMethod $captureMethod = CaptureMethod::Automatic,
    ?Money $amount = null,
    ?PaymentInstrument $instrument = null,
    ?ChallengeResult $challengeResult = null,
    PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
    ?MerchantDescriptor $merchantDescriptor = null,
    string $description = '',
): CreatePaymentIntentCommand {
    return new readonly class($id, $captureMethod, $amount ?? makeAmount(), $instrument ?? makeInstrument(), $challengeResult, $initiation, $merchantDescriptor ?? makeMerchantDescriptor(), $description) implements CreatePaymentIntentCommand
    {
        public function __construct(
            private PaymentIntentId $paymentIntentId,
            private CaptureMethod $captureMethod,
            private Money $amount,
            private PaymentInstrument $instrument,
            private ?ChallengeResult $challengeResult,
            private PaymentInitiation $initiation,
            private MerchantDescriptor $merchantDescriptor,
            private string $description,
        ) {}

        public function paymentIntentId(): PaymentIntentId { return $this->paymentIntentId; }
        public function amount(): Money { return $this->amount; }
        public function instrument(): PaymentInstrument { return $this->instrument; }
        public function captureMethod(): CaptureMethod { return $this->captureMethod; }
        public function billingAddress(): BillingAddress { return makeBillingAddress(); }
        public function merchantDescriptor(): MerchantDescriptor { return $this->merchantDescriptor; }
        public function description(): string { return $this->description; }
        public function metadata(): array { return []; }
        public function challengeResult(): ?ChallengeResult { return $this->challengeResult; }
        public function initiation(): PaymentInitiation { return $this->initiation; }
        public function connection(): ?ConnectionContext { return null; }
        public function gatewayId(): ?string { return null; }
    };
}

function makeCapturePiCommand(PaymentIntentId $id, ?Money $amount = null): CapturePaymentIntentCommand
{
    return new readonly class($id, $amount ?? makeAmount()) implements CapturePaymentIntentCommand
    {
        public function __construct(private PaymentIntentId $paymentIntentId, private Money $amount) {}
        public function paymentIntentId(): PaymentIntentId { return $this->paymentIntentId; }
        public function amount(): Money { return $this->amount; }
    };
}

function makeCancelPiCommand(PaymentIntentId $id, string $reason = 'user requested'): CancelPaymentIntentCommand
{
    return new readonly class($id, $reason) implements CancelPaymentIntentCommand
    {
        public function __construct(private PaymentIntentId $paymentIntentId, private string $reason) {}
        public function paymentIntentId(): PaymentIntentId { return $this->paymentIntentId; }
        public function reason(): string { return $this->reason; }
    };
}

function makePiFeeCommand(PaymentIntentId $id, Money $fee, DateTimeImmutable $observedAt): RecordPaymentIntentFeeCommand
{
    return new readonly class($id, $fee, $observedAt) implements RecordPaymentIntentFeeCommand
    {
        public function __construct(private PaymentIntentId $paymentIntentId, private Money $fee, private DateTimeImmutable $observedAt) {}
        public function paymentIntentId(): PaymentIntentId { return $this->paymentIntentId; }
        public function fee(): Money { return $this->fee; }
        public function observedAt(): DateTimeImmutable { return $this->observedAt; }
    };
}

// ──────────────────────────────────────────────
//  Port stubs (live and webhook flows look identical from the aggregate)
// ──────────────────────────────────────────────

function makePaySuccessPort(?Money $convertedAmount = null): CreatePort
{
    return new readonly class($convertedAmount) implements CreatePort
    {
        public function __construct(private ?Money $convertedAmount) {}
        public function create(CreateRequest $request): CreateOutcome { return new CreateOutcome(convertedAmount: $this->convertedAmount); }
    };
}

function makePayChallengePort(Challenge $challenge): CreatePort
{
    return new readonly class($challenge) implements CreatePort
    {
        public function __construct(private Challenge $challenge) {}
        public function create(CreateRequest $request): CreateOutcome { return new CreateOutcome(challenge: $this->challenge); }
    };
}

/**
 * The webhook flow's {@see ConfirmChallengePort}: the gateway raised the
 * authentication against a payment it had already opened and settled it itself, so
 * completing it costs no call. Stands in for
 * `Laravel\Webhook\Service\Port\ExternallyCompletedConfirmChallengePort`, which
 * this package cannot reach.
 */
function makeExternallyCompletedConfirmPort(): ConfirmChallengePort
{
    return new readonly class implements ConfirmChallengePort
    {
        public function confirm(ConfirmChallengeRequest $request): ConfirmChallengeOutcome { return ConfirmChallengeOutcome::placed(); }
    };
}

/**
 * The live {@see ConfirmChallengePort}: places the payment inspection would not
 * let through unauthenticated, now that the cardholder has answered.
 */
function makeConfirmSuccessPort(?Money $convertedAmount = null): ConfirmChallengePort
{
    return new readonly class($convertedAmount) implements ConfirmChallengePort
    {
        public function __construct(private ?Money $convertedAmount) {}
        public function confirm(ConfirmChallengeRequest $request): ConfirmChallengeOutcome { return ConfirmChallengeOutcome::placed($this->convertedAmount); }
    };
}

function makeConfirmDeclinedPort(string $reason): ConfirmChallengePort
{
    return new readonly class($reason) implements ConfirmChallengePort
    {
        public function __construct(private string $reason) {}
        public function confirm(ConfirmChallengeRequest $request): ConfirmChallengeOutcome { throw new GatewayDeclinedException($this->reason); }
    };
}

/**
 * A {@see ConfirmChallengePort} that fails the test if it is called — for the
 * paths that must not reach a gateway at all.
 */
function makeUnreachableConfirmPort(): ConfirmChallengePort
{
    return new readonly class implements ConfirmChallengePort
    {
        public function confirm(ConfirmChallengeRequest $request): ConfirmChallengeOutcome
        {
            throw new RuntimeException('ConfirmChallengePort::confirm() must not be reached here.');
        }
    };
}

function makePayDeclinedPort(string $reason): CreatePort
{
    return new readonly class($reason) implements CreatePort
    {
        public function __construct(private string $reason) {}
        public function create(CreateRequest $request): CreateOutcome { throw new GatewayDeclinedException($this->reason); }
    };
}

function makeCaptureSuccessPort(?Money $convertedAmount = null): CapturePort
{
    return new readonly class($convertedAmount) implements CapturePort
    {
        public function __construct(private ?Money $convertedAmount) {}
        public function capture(CaptureRequest $request): CaptureOutcome { return new CaptureOutcome($this->convertedAmount); }
    };
}

function makeCaptureDeclinedPort(string $reason): CapturePort
{
    return new readonly class($reason) implements CapturePort
    {
        public function __construct(private string $reason) {}
        public function capture(CaptureRequest $request): CaptureOutcome { throw new GatewayDeclinedException($this->reason); }
    };
}

function makeVoidSuccessPort(): CancelPort
{
    return new readonly class implements CancelPort
    {
        public function cancel(CancelRequest $request): void {}
    };
}

function makeVoidDeclinedPort(string $reason): CancelPort
{
    return new readonly class($reason) implements CancelPort
    {
        public function __construct(private string $reason) {}
        public function cancel(CancelRequest $request): void { throw new GatewayDeclinedException($this->reason); }
    };
}

function makeRefundSuccessPort(): RefundPort
{
    return new readonly class implements RefundPort
    {
        public function refund(RefundRequest $request): void {}
    };
}

function makeRefundDeclinedPort(string $reason): RefundPort
{
    return new readonly class($reason) implements RefundPort
    {
        public function __construct(private string $reason) {}
        public function refund(RefundRequest $request): void { throw new GatewayDeclinedException($this->reason); }
    };
}

function makeCreateRefundCommand(RefundId $id, Money $amount, ?PaymentInstrument $retryInstrument = null): CreateRefundCommand
{
    return new readonly class($id, $amount, $retryInstrument) implements CreateRefundCommand
    {
        public function __construct(private RefundId $refundId, private Money $amount, private ?PaymentInstrument $retryInstrument) {}
        public function refundId(): RefundId { return $this->refundId; }
        public function amount(): Money { return $this->amount; }
        public function retryInstrument(): ?PaymentInstrument { return $this->retryInstrument; }
    };
}

function makeRecordRefundFeeCommand(RefundId $id, Money $fee, DateTimeImmutable $observedAt): RecordRefundFeeCommand
{
    return new readonly class($id, $fee, $observedAt) implements RecordRefundFeeCommand
    {
        public function __construct(private RefundId $refundId, private Money $fee, private DateTimeImmutable $observedAt) {}
        public function refundId(): RefundId { return $this->refundId; }
        public function fee(): Money { return $this->fee; }
        public function observedAt(): DateTimeImmutable { return $this->observedAt; }
    };
}

// ──────────────────────────────────────────────
//  Create — gateway success branches
// ──────────────────────────────────────────────

it('records PaymentIntentCharged on create with Immediate + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));
});

it('records PaymentIntentCharged carrying the FX convertedAmount from the port', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $converted = new Money(1142, new Currency('EUR'));

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate),
        makePaySuccessPort($converted),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        null,
        $converted,
    ));
});

it('records PaymentIntentAuthorized on create with Automatic + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));
});

it('records PaymentIntentAuthorized on create with Manual + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Manual),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));
});

// ──────────────────────────────────────────────
//  Create — gateway non-success branches
// ──────────────────────────────────────────────

it('records PaymentIntentFailed on create with GatewayDeclined', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id),
        makePayDeclinedPort('insufficient_funds'),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        'insufficient_funds',
        FailureCode::GatewayDeclined,
    ));
});

it('records PaymentIntentRequiresAction on create with GatewayChallengeRequired', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id),
        makePayChallengePort(makeThreeDSChallenge()),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));
});

it('forwards pre-auth ChallengeResult into the initial event', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $preAuth = makePiThreeDSResult();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, challengeResult: $preAuth),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        $preAuth,
    ));
});

it('binds the payment initiation onto the aggregate and the recorded event', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate, initiation: PaymentInitiation::MerchantRecurring),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    expect($aggregate->initiation())->toBe(PaymentInitiation::MerchantRecurring);
    then(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
        initiation: PaymentInitiation::MerchantRecurring,
    ));
});

it('snapshot roundtrip preserves the payment initiation', function () {
    $id = PaymentIntentId::generate();
    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate, initiation: PaymentInitiation::MerchantUnscheduled),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );

    $state = (fn () => $this->createSnapshotState())->call($aggregate);
    $restored = (fn () => PaymentIntentAggregate::reconstituteFromSnapshotState($id, $state))->call($aggregate);

    expect($restored->initiation())->toBe(PaymentInitiation::MerchantUnscheduled);

    then();
});

// ──────────────────────────────────────────────
//  Create — statement descriptor and description
// ──────────────────────────────────────────────

it('carries the descriptor and description onto the charge', function () {
    // Both used to travel as opaque `metadata` keys, which meant nothing
    // required them and nothing typed them. They are first-class now because a
    // projector has to write them into their own columns, and because the
    // descriptor is the one field of a payment the cardholder actually reads.
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(
            $id,
            CaptureMethod::Immediate,
            merchantDescriptor: new MerchantDescriptor('ACME STORE'),
            description: 'Order 4417',
        ),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    expect($aggregate->merchantDescriptor())->toEqual(new MerchantDescriptor('ACME STORE'))
        ->and($aggregate->description())->toBe('Order 4417');

    then(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        new MerchantDescriptor('ACME STORE'),
        'Order 4417',
    ));
});

it('carries the descriptor and description onto the authorization', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(
            $id,
            CaptureMethod::Manual,
            merchantDescriptor: new MerchantDescriptor('ACME STORE'),
            description: 'Order 4417',
        ),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        new MerchantDescriptor('ACME STORE'),
        'Order 4417',
    ));
});

it('carries the descriptor and description onto a gateway decline', function () {
    $id = PaymentIntentId::generate();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(
            $id,
            CaptureMethod::Immediate,
            merchantDescriptor: new MerchantDescriptor('ACME STORE'),
            description: 'Order 4417',
        ),
        makePayDeclinedPort('insufficient funds'),
        StubPaymentIntentFirewall::allowing(),
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::Failed)
        ->and($aggregate->merchantDescriptor())->toEqual(new MerchantDescriptor('ACME STORE'))
        ->and($aggregate->description())->toBe('Order 4417');

    then();
});

it('keeps the descriptor and description across the challenge confirmation', function () {
    // confirmChallenge() rebuilds the charge from aggregate state rather than
    // from a command, so anything the state forgets is silently dropped on the
    // 3DS path only — which is exactly the shape of bug that survives a test
    // suite covering the inline path.
    $id = PaymentIntentId::generate();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(
            $id,
            CaptureMethod::Immediate,
            merchantDescriptor: new MerchantDescriptor('ACME STORE'),
            description: 'Order 4417',
        ),
        makePayChallengePort(makeRedirectChallenge()),
        StubPaymentIntentFirewall::allowing(),
    );
    $aggregate->confirmChallenge(makePiThreeDSResult(), makeExternallyCompletedConfirmPort());

    expect($aggregate->status())->toBe(PaymentIntentStatus::Charged)
        ->and($aggregate->merchantDescriptor())->toEqual(new MerchantDescriptor('ACME STORE'))
        ->and($aggregate->description())->toBe('Order 4417');

    then();
});

it('snapshot roundtrip preserves the descriptor and description', function () {
    $id = PaymentIntentId::generate();
    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(
            $id,
            CaptureMethod::Immediate,
            merchantDescriptor: new MerchantDescriptor('ACME STORE'),
            description: 'Order 4417',
        ),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );

    $state = (fn () => $this->createSnapshotState())->call($aggregate);
    $restored = (fn () => PaymentIntentAggregate::reconstituteFromSnapshotState($id, $state))->call($aggregate);

    expect($restored->merchantDescriptor())->toEqual(new MerchantDescriptor('ACME STORE'))
        ->and($restored->description())->toBe('Order 4417');

    then();
});

it('round-trips the descriptor and description through every event payload', function (object $event) {
    $restored = $event::fromPayload($event->toPayload());

    expect($restored->merchantDescriptor)->toEqual($event->merchantDescriptor)
        ->and($restored->description)->toBe($event->description);
})->with(function () {
    $descriptor = new MerchantDescriptor('ACME STORE');

    yield 'charged' => fn () => new PaymentIntentCharged(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [], $descriptor, 'Order 4417',
    );
    yield 'authorized' => fn () => new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [], $descriptor, 'Order 4417',
    );
    yield 'failed' => fn () => new PaymentIntentFailed(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [], $descriptor, 'Order 4417', 'declined', FailureCode::GatewayDeclined,
    );
    yield 'requires action' => fn () => new PaymentIntentRequiresAction(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [], $descriptor, 'Order 4417', makeRedirectChallenge(),
    );
    yield 'imported' => fn () => new PaymentIntentImported(
        makeAmount(), PaymentIntentStatus::Charged, makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), $descriptor, 'Order 4417',
    );
});

// ──────────────────────────────────────────────
//  Create — invariants
// ──────────────────────────────────────────────

it('throws InvalidPaymentIntent for zero amount', function () {
    $id = $this->aggregateRootId();
    $zero = new Money(0, new Currency('USD'));

    PaymentIntentAggregate::create(
        makeCreatePiCommand($id, amount: $zero),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
})->throws(InvalidPaymentIntent::class, 'Payment intent amount must be positive.');

it('throws InvalidPaymentIntent for negative amount', function () {
    $id = $this->aggregateRootId();
    $negative = new Money(-100, new Currency('USD'));

    PaymentIntentAggregate::create(
        makeCreatePiCommand($id, amount: $negative),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
})->throws(InvalidPaymentIntent::class, 'Payment intent amount must be positive.');

it('throws InvalidPaymentIntent for unusable instrument', function () {
    $id = $this->aggregateRootId();

    PaymentIntentAggregate::create(
        makeCreatePiCommand($id, instrument: makeUnusableInstrument()),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
})->throws(InvalidPaymentIntent::class, 'Payment source is not usable');

it('throws InvalidPaymentIntent for a hosted payment with a deferred capture method', function (CaptureMethod $captureMethod) {
    $id = $this->aggregateRootId();

    PaymentIntentAggregate::create(
        makeCreatePiCommand($id, $captureMethod, instrument: makeHostedPaymentForPI()),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
})->with([
    CaptureMethod::Automatic,
    CaptureMethod::Manual,
])->throws(InvalidPaymentIntent::class, 'only immediate capture is possible');

it('accepts a hosted payment with immediate capture', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate, instrument: makeHostedPaymentForPI()),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    $this->persistAggregateRoot($aggregate);

    expect($aggregate->status())->toBe(PaymentIntentStatus::Charged);

    then(new PaymentIntentCharged(
        makeAmount(),
        makeHostedPaymentForPI(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));
});

// ──────────────────────────────────────────────
//  confirmChallenge — success branches
// ──────────────────────────────────────────────

it('records PaymentIntentAuthorized on confirmChallenge after RequiresAction with Automatic', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge(makePiThreeDSResult(), makeExternallyCompletedConfirmPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makePiThreeDSResult(),
    ));
});

it('records PaymentIntentCharged on confirmChallenge after RequiresAction with Immediate', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge(makePiThreeDSResult(), makeExternallyCompletedConfirmPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makePiThreeDSResult(),
    ));
});

it('treats ThreeDSStatus::NotAvailable as success (liability shift)', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = makePiThreeDSResult(ThreeDSStatus::NotAvailable);

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result, makeExternallyCompletedConfirmPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        $result,
    ));
});

it('treats ThreeDSStatus::Info as success (data share only, not a refusal)', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = makePiThreeDSResult(ThreeDSStatus::Info);

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result, makeExternallyCompletedConfirmPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        $result,
    ));
});

it('treats RedirectResult as success', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = new RedirectResult(transactionId: 'pay-77');

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeRedirectChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result, makeExternallyCompletedConfirmPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        $result,
    ));
});

// ──────────────────────────────────────────────
//  confirmChallenge — through a CreatePort that places the payment
// ──────────────────────────────────────────────

it('places the payment on confirmChallenge when the gateway never received it', function () {
    // The other implementation of the same port. Inspection parks the payment
    // before any gateway call is spent, so clearing the challenge is what places
    // it. The FX figure is what proves the port was asked: nothing in the
    // aggregate's own state could have produced it.
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $converted = new Money(1142, new Currency('EUR'));

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge(makePiThreeDSResult(), makeConfirmSuccessPort($converted));
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makePiThreeDSResult(),
        $converted,
    ));
});

it('forwards the resolved authentication to the gateway as the payment evidence', function () {
    // What the liability shift is claimed with. An authentication that is run and
    // then not forwarded leaves the issuer seeing an unauthenticated payment,
    // which is the whole reason for running it.
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = makePiThreeDSResult();

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $port = new class implements ConfirmChallengePort
    {
        public ?ConfirmChallengeRequest $request = null;

        public function confirm(ConfirmChallengeRequest $request): ConfirmChallengeOutcome
        {
            $this->request = $request;

            return ConfirmChallengeOutcome::placed();
        }
    };

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result, $port);
    $this->persistAggregateRoot($aggregate);

    expect($port->request)->not->toBeNull()
        ->and($port->request->challengeResult)->toBe($result)
        ->and($port->request->paymentIntentId->toString())->toBe($id->toString())
        ->and($port->request->amount)->toEqual(makeAmount())
        ->and($port->request->instrument)->toEqual(makeInstrument())
        ->and($port->request->captureMethod)->toBe(CaptureMethod::Manual)
        ->and($port->request->billingAddress)->toEqual(makeBillingAddress());

    then(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        $result,
    ));
});

it('records PaymentIntentFailed when the gateway declines the authenticated payment', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = makePiThreeDSResult();

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result, makeConfirmDeclinedPort('insufficient funds'));
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        'insufficient funds',
        FailureCode::GatewayDeclined,
        $result,
    ));
});

// ──────────────────────────────────────────────
//  confirmChallenge — failure branches
// ──────────────────────────────────────────────

it('records PaymentIntentFailed on confirmChallenge with NotAuthenticated', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = makePiThreeDSResult(ThreeDSStatus::NotAuthenticated);

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result, makeUnreachableConfirmPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        '3DS authentication: N',
        FailureCode::AuthenticationFailed,
        $result,
    ));
});

it('records PaymentIntentFailed on confirmChallenge with Rejected', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = makePiThreeDSResult(ThreeDSStatus::Rejected);

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result, makeUnreachableConfirmPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        '3DS authentication: R',
        FailureCode::AuthenticationFailed,
        $result,
    ));
});

it('throws PaymentIntentChallengeNotPending when confirmChallenge called outside RequiresAction', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge(makePiThreeDSResult(), makeUnreachableConfirmPort());
})->throws(PaymentIntentChallengeNotPending::class);

// ──────────────────────────────────────────────
//  Capture — through CapturePort
// ──────────────────────────────────────────────

it('records PaymentIntentCaptured on capture from Authorized + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->capture(makeCapturePiCommand($id), makeCaptureSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCaptured(makeAmount()));
});

it('records PaymentIntentCaptured carrying the FX convertedAmount from the port', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $converted = new Money(9140, new Currency('USD'));

    given(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->capture(makeCapturePiCommand($id), makeCaptureSuccessPort($converted));
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCaptured(makeAmount(), $converted));
});

it('records PaymentIntentCaptured with partial amount', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $partial = new Money(500, new Currency('USD'));

    given(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->capture(makeCapturePiCommand($id, $partial), makeCaptureSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCaptured($partial));
});

/**
 * The inverse of what this asserted before. Unlike create / confirmChallenge /
 * cancel, capture does not convert a refusal into `PaymentIntentFailed`:
 * capturing an existing authorization has no business failure mode, so a refusal
 * is infrastructural, the caller retries, and recording a failure would assert
 * something untrue while the funds may still be held.
 */
it('lets a refused capture propagate instead of recording PaymentIntentFailed', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->capture(makeCapturePiCommand($id), makeCaptureDeclinedPort('issuer_unavailable'));
})->throws(GatewayDeclinedException::class, 'issuer_unavailable');

it('records nothing and stays authorized when a capture is refused', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);

    try {
        $aggregate->capture(makeCapturePiCommand($id), makeCaptureDeclinedPort('issuer_unavailable'));
    } catch (GatewayDeclinedException) {
        // asserted by the sibling test; the point here is that a retry is possible
    }

    expect($aggregate->status())->toBe(PaymentIntentStatus::Authorized);

    $this->persistAggregateRoot($aggregate);

    then();
});

it('throws PaymentIntentCannotBeCaptured on capture from Charged', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->capture(makeCapturePiCommand($id), makeCaptureSuccessPort());
})->throws(PaymentIntentCannotBeCaptured::class);

// ──────────────────────────────────────────────
//  Cancel — through CancelPort
// ──────────────────────────────────────────────

it('records PaymentIntentCancelled on cancel from Authorized + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelPiCommand($id, 'fraud check'), makeVoidSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCancelled('fraud check'));
});

it('records PaymentIntentCancelled on cancel from RequiresAction + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelPiCommand($id, 'timeout'), makeVoidSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCancelled('timeout'));
});

it('records PaymentIntentFailed on cancel + GatewayDeclined', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelPiCommand($id), makeVoidDeclinedPort('void_not_allowed'));
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        'void_not_allowed',
        FailureCode::GatewayDeclined,
    ));
});

it('throws PaymentIntentCannotBeCancelled when already Charged', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelPiCommand($id), makeVoidSuccessPort());
})->throws(PaymentIntentCannotBeCancelled::class);

it('throws PaymentIntentCannotBeCancelled when already Cancelled', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(
        new PaymentIntentAuthorized(makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [], makeMerchantDescriptor(), ''),
        new PaymentIntentCancelled('first cancel'),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelPiCommand($id), makeVoidSuccessPort());
})->throws(PaymentIntentCannotBeCancelled::class);

// ──────────────────────────────────────────────
//  Refund (untouched)
// ──────────────────────────────────────────────

it('records RefundProcessed with retryInstrument when alternative card supplied', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $refundId = RefundId::generate();
    $retry = makeCreditCardForPI();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(
        makeCreateRefundCommand($refundId, makeAmount(), $retry),
        makeRefundSuccessPort(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new RefundProcessed($refundId, makeAmount(), $retry));
});

it('records RefundFailed with retryInstrument when alternative card declines', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $refundId = RefundId::generate();
    $retry = makeCreditCardForPI();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(
        makeCreateRefundCommand($refundId, makeAmount(), $retry),
        makeRefundDeclinedPort('do_not_honor'),
    );
    $this->persistAggregateRoot($aggregate);

    then(new RefundFailed($refundId, makeAmount(), 'do_not_honor', $retry));
});

it('records RefundProcessed (full) on refund from Charged + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $refundId = RefundId::generate();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(makeCreateRefundCommand($refundId, makeAmount()), makeRefundSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new RefundProcessed($refundId, makeAmount()));
});

it('records RefundProcessed (partial) and stays charged', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $partial = new Money(400, new Currency('USD'));
    $refundId = RefundId::generate();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(makeCreateRefundCommand($refundId, $partial), makeRefundSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new RefundProcessed($refundId, $partial));
});

it('allows two partial refunds that sum to full amount', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $first = RefundId::generate();
    $second = RefundId::generate();

    given(
        new PaymentIntentCharged(makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [], makeMerchantDescriptor(), ''),
        new RefundProcessed($first, new Money(400, new Currency('USD'))),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(makeCreateRefundCommand($second, new Money(600, new Currency('USD'))), makeRefundSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new RefundProcessed($second, new Money(600, new Currency('USD'))));
});

it('records RefundFailed when gateway declines the refund', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $refundId = RefundId::generate();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(
        makeCreateRefundCommand($refundId, makeAmount()),
        makeRefundDeclinedPort('refund_window_expired'),
    );
    $this->persistAggregateRoot($aggregate);

    then(new RefundFailed($refundId, makeAmount(), 'refund_window_expired'));
});

it('failed refund does not consume refundable amount', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $first = RefundId::generate();
    $second = RefundId::generate();

    given(
        new PaymentIntentCharged(makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [], makeMerchantDescriptor(), ''),
        new RefundFailed($first, makeAmount(), 'declined'),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(makeCreateRefundCommand($second, makeAmount()), makeRefundSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new RefundProcessed($second, makeAmount()));
});

it('throws PaymentIntentRefundExceedsAmount when refund exceeds remaining', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $tooMuch = new Money(1500, new Currency('USD'));

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(makeCreateRefundCommand(RefundId::generate(), $tooMuch), makeRefundSuccessPort());
})->throws(PaymentIntentRefundExceedsAmount::class);

it('throws PaymentIntentCannotBeRefunded when not Charged (Authorized)', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(makeCreateRefundCommand(RefundId::generate(), makeAmount()), makeRefundSuccessPort());
})->throws(PaymentIntentCannotBeRefunded::class);

it('records RefundFeeRecorded for an existing refund', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $refundId = RefundId::generate();
    $fee = new Money(15, new Currency('USD'));
    $observedAt = new DateTimeImmutable('2026-04-29T16:00:00Z');

    given(
        new PaymentIntentCharged(makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [], makeMerchantDescriptor(), ''),
        new RefundProcessed($refundId, makeAmount()),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->recordRefundFee(makeRecordRefundFeeCommand($refundId, $fee, $observedAt));
    $this->persistAggregateRoot($aggregate);

    then(new RefundFeeRecorded($refundId, $fee, $observedAt));
});

it('throws RefundNotFound when recording fee for unknown refund', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->recordRefundFee(makeRecordRefundFeeCommand(
        RefundId::generate(),
        new Money(5, new Currency('USD')),
        new DateTimeImmutable('2026-04-29T16:00:00Z'),
    ));
})->throws(RefundNotFound::class);

// ──────────────────────────────────────────────
//  Imported
// ──────────────────────────────────────────────

it('imports an intent the gateway already holds without touching a port', function () {
    // The distinction from create(): that one spends a CreatePort::create() to
    // make the intent. Against an intent the gateway opened moments ago, calling
    // it would take the money a second time — so import() takes no port at all,
    // and this test would fail to construct if it did.
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->import(
        amount: new Money(5000, new Currency('USD')),
        status: PaymentIntentStatus::RequiresAction,
        instrument: HostedPayment::unknown(),
        captureMethod: CaptureMethod::Manual,
        billingAddress: BillingAddress::unknown(),
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentImported(
        amount: new Money(5000, new Currency('USD')),
        status: PaymentIntentStatus::RequiresAction,
        instrument: HostedPayment::unknown(),
        captureMethod: CaptureMethod::Manual,
        billingAddress: BillingAddress::unknown(),
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
    ));
});

it('refuses to import over an intent that already exists', function () {
    // Importing onto a live intent would rewrite its amount and instrument from
    // whatever the export happened to say and lose what it had progressed to.
    given(new PaymentIntentImported(
        amount: new Money(2000, new Currency('EUR')),
        status: PaymentIntentStatus::Charged,
        instrument: makeImportedPaymentMethod(),
        captureMethod: CaptureMethod::Automatic,
        billingAddress: makeBillingAddress(),
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
    ));

    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $aggregate = $this->retrieveAggregateRoot($id);

    expect(fn () => $aggregate->import(
        amount: new Money(5000, new Currency('USD')),
        status: PaymentIntentStatus::RequiresAction,
        instrument: HostedPayment::unknown(),
        captureMethod: CaptureMethod::Manual,
        billingAddress: BillingAddress::unknown(),
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
    ))->toThrow(InvalidPaymentIntent::class, 'already exists');
});

it('finishes an intent imported as RequiresAction through the ordinary challenge path', function () {
    // Why RequiresAction is the right state to open an unresolved intent in, and
    // not a stand-in for a missing "created" case: the webhook that reports the
    // outcome resolves it through machinery that already exists. Nothing had to
    // learn about imported intents.
    given(new PaymentIntentImported(
        amount: new Money(5000, new Currency('USD')),
        status: PaymentIntentStatus::RequiresAction,
        instrument: HostedPayment::unknown(),
        captureMethod: CaptureMethod::Manual,
        billingAddress: BillingAddress::unknown(),
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
    ));

    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge(new RedirectResult('pi_123'), makeExternallyCompletedConfirmPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        amount: new Money(5000, new Currency('USD')),
        instrument: HostedPayment::unknown(),
        captureMethod: CaptureMethod::Manual,
        billingAddress: BillingAddress::unknown(),
        metadata: [],
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
        challengeResult: new RedirectResult('pi_123'),
    ));
});

it('reads a legacy import that stored no billing address as the no-data marker', function () {
    // Guards the coercion in fromPayload() and nothing wider than that: the
    // event store serialises through a property normaliser, which reaches the
    // constructor directly, so this path has no production caller.
    $legacy = [
        'amount' => '5000',
        'currency' => 'USD',
        'status' => PaymentIntentStatus::RequiresAction->value,
        'instrument' => HostedPayment::unknown()->toPayload(),
        'capture_method' => CaptureMethod::Manual->value,
        'billing_address' => null,
        'merchant_descriptor' => 'ACME STORE',
        'description' => '',
    ];

    expect(PaymentIntentImported::fromPayload($legacy)->billingAddress)
        ->toEqual(BillingAddress::unknown());
});

it('applies PaymentIntentImported and allows refund up to the imported amount', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentImported(
        amount: new Money(2000, new Currency('EUR')),
        status: PaymentIntentStatus::Charged,
        instrument: makeImportedPaymentMethod(),
        captureMethod: CaptureMethod::Automatic,
        billingAddress: makeBillingAddress(),
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $refundId = RefundId::generate();
    $aggregate->refund(
        makeCreateRefundCommand($refundId, new Money(1500, new Currency('EUR'))),
        makeRefundSuccessPort(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new RefundProcessed($refundId, new Money(1500, new Currency('EUR'))));
});

it('applies RefundImported and projects refund into refunds()', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $refundId = RefundId::generate();
    $importedAmount = new Money(800, new Currency('EUR'));

    given(
        new PaymentIntentImported(
            amount: new Money(2000, new Currency('EUR')),
            status: PaymentIntentStatus::Charged,
            instrument: makeImportedPaymentMethod(),
            captureMethod: CaptureMethod::Automatic,
            billingAddress: makeBillingAddress(),
            merchantDescriptor: makeMerchantDescriptor(),
            description: '',
        ),
        new RefundImported($refundId, $importedAmount, RefundStatus::Processed),
    );

    $aggregate = $this->retrieveAggregateRoot($id);

    expect($aggregate->refunds())->toHaveCount(1)
        ->and($aggregate->refunds()[$refundId->toString()]->status())->toBe(RefundStatus::Processed)
        ->and($aggregate->refunds()[$refundId->toString()]->amount()->getAmount())->toBe('800')
        ->and($aggregate->refundableAmount()->getAmount())->toBe('1200');
});

// ──────────────────────────────────────────────
//  Fee
// ──────────────────────────────────────────────

it('records PaymentIntentFeeRecorded from any state', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $fee = new Money(35, new Currency('USD'));
    $observedAt = new DateTimeImmutable('2026-04-29T12:00:00Z');

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->recordFee(makePiFeeCommand($id, $fee, $observedAt));
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFeeRecorded($fee, $observedAt));
});

// ──────────────────────────────────────────────
//  Serialization roundtrips
// ──────────────────────────────────────────────

it('PaymentIntentAuthorized survives serialization roundtrip', function () {
    $event = new PaymentIntentAuthorized(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Manual,
        makeBillingAddress(),
        ['k' => 'v'],
        makeMerchantDescriptor(),
        '',
        makePiThreeDSResult(),
    );
    $restored = PaymentIntentAuthorized::fromPayload($event->toPayload());

    expect($restored->amount->getAmount())->toBe('1000')
        ->and($restored->captureMethod)->toBe(CaptureMethod::Manual)
        ->and($restored->metadata)->toBe(['k' => 'v'])
        ->and($restored->challengeResult)->toBeInstanceOf(ThreeDSResult::class);

    then();
});

it('PaymentIntentCharged survives serialization roundtrip without challenge result', function () {
    $event = new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    );
    $restored = PaymentIntentCharged::fromPayload($event->toPayload());

    expect($restored->amount->getAmount())->toBe('1000')
        ->and($restored->captureMethod)->toBe(CaptureMethod::Immediate)
        ->and($restored->challengeResult)->toBeNull();

    then();
});

it('PaymentIntentRequiresAction survives serialization roundtrip (3DS)', function () {
    $event = new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    );
    $restored = PaymentIntentRequiresAction::fromPayload($event->toPayload());

    expect($restored->challenge)->toBeInstanceOf(ThreeDSChallenge::class)
        ->and($restored->challenge->url)->toBe('https://acs.example.com/challenge');

    then();
});

it('PaymentIntentRequiresAction survives serialization roundtrip (Redirect)', function () {
    $event = new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeRedirectChallenge(),
    );
    $restored = PaymentIntentRequiresAction::fromPayload($event->toPayload());

    expect($restored->challenge)->toBeInstanceOf(RedirectChallenge::class)
        ->and($restored->challenge->url)->toBe('https://hosted.example/checkout');

    then();
});

it('PaymentIntentFailed survives serialization roundtrip', function () {
    $event = new PaymentIntentFailed(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        'card_declined',
        FailureCode::GatewayDeclined,
        makePiThreeDSResult(),
    );
    $restored = PaymentIntentFailed::fromPayload($event->toPayload());

    expect($restored->reason)->toBe('card_declined')
        ->and($restored->challengeResult)->toBeInstanceOf(ThreeDSResult::class);

    then();
});

it('PaymentIntentCancelled survives serialization roundtrip', function () {
    $event = new PaymentIntentCancelled('user_request');
    $restored = PaymentIntentCancelled::fromPayload($event->toPayload());

    expect($restored->reason)->toBe('user_request');

    then();
});

it('PaymentIntentCaptured survives serialization roundtrip', function () {
    $event = new PaymentIntentCaptured(new Money(800, new Currency('USD')));
    $restored = PaymentIntentCaptured::fromPayload($event->toPayload());

    expect($restored->capturedAmount->getAmount())->toBe('800');

    then();
});

it('PaymentIntentImported survives serialization roundtrip', function () {
    $event = new PaymentIntentImported(
        amount: new Money(3000, new Currency('GBP')),
        status: PaymentIntentStatus::Charged,
        instrument: makeImportedPaymentMethod(),
        captureMethod: CaptureMethod::Manual,
        billingAddress: makeBillingAddressFull(),
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
    );

    $restored = PaymentIntentImported::fromPayload($event->toPayload());

    expect($restored->amount->getAmount())->toBe('3000')
        ->and($restored->status)->toBe(PaymentIntentStatus::Charged)
        ->and((string) $restored->billingAddress->state)->toBe('NY');

    then();
});

it('imports a hosted-flow PaymentIntent with no billing details of its own', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentImported(
        amount: new Money(2000, new Currency('EUR')),
        status: PaymentIntentStatus::Charged,
        instrument: new HostedPayment('', ''),
        captureMethod: CaptureMethod::Automatic,
        billingAddress: BillingAddress::unknown(),
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);

    // The marker, not null: a hosted intent has no merchant-side billing on file,
    // and saying so with the "no data" sentinel keeps it resolvable — the events
    // that would finish it all require an address.
    expect($aggregate->billingAddress())->toEqual(BillingAddress::unknown())
        ->and($aggregate->instrument())->toBeInstanceOf(HostedPayment::class);

    then();
});

it('hosted-flow PaymentIntentImported survives serialization roundtrip', function () {
    $event = new PaymentIntentImported(
        amount: new Money(3000, new Currency('GBP')),
        status: PaymentIntentStatus::Charged,
        instrument: new HostedPayment('', ''),
        captureMethod: CaptureMethod::Automatic,
        billingAddress: BillingAddress::unknown(),
        merchantDescriptor: makeMerchantDescriptor(),
        description: '',
    );

    $restored = PaymentIntentImported::fromPayload($event->toPayload());

    expect($restored->billingAddress)->toEqual(BillingAddress::unknown())
        ->and($restored->instrument)->toBeInstanceOf(HostedPayment::class);

    then();
});

it('PaymentIntentFeeRecorded survives serialization roundtrip', function () {
    $event = new PaymentIntentFeeRecorded(
        new Money(120, new Currency('EUR')),
        new DateTimeImmutable('2026-04-29T15:30:00+02:00'),
    );
    $restored = PaymentIntentFeeRecorded::fromPayload($event->toPayload());

    expect($restored->fee->getAmount())->toBe('120');

    then();
});

it('RefundProcessed survives serialization roundtrip', function () {
    $refundId = RefundId::generate();
    $event = new RefundProcessed($refundId, new Money(600, new Currency('USD')));
    $restored = RefundProcessed::fromPayload($event->toPayload());

    expect($restored->refundId->toString())->toBe($refundId->toString())
        ->and($restored->amount->getAmount())->toBe('600');

    then();
});

it('RefundFailed survives serialization roundtrip', function () {
    $refundId = RefundId::generate();
    $event = new RefundFailed($refundId, new Money(300, new Currency('EUR')), 'declined');
    $restored = RefundFailed::fromPayload($event->toPayload());

    expect($restored->refundId->toString())->toBe($refundId->toString())
        ->and($restored->amount->getAmount())->toBe('300')
        ->and($restored->reason)->toBe('declined');

    then();
});

it('RefundFeeRecorded survives serialization roundtrip', function () {
    $refundId = RefundId::generate();
    $event = new RefundFeeRecorded(
        $refundId,
        new Money(25, new Currency('USD')),
        new DateTimeImmutable('2026-04-29T18:00:00Z'),
    );
    $restored = RefundFeeRecorded::fromPayload($event->toPayload());

    expect($restored->refundId->toString())->toBe($refundId->toString())
        ->and($restored->fee->getAmount())->toBe('25');

    then();
});

// ──────────────────────────────────────────────
//  Snapshot roundtrip
// ──────────────────────────────────────────────

it('snapshot roundtrip preserves charged state with challenge_result', function () {
    $id = PaymentIntentId::generate();
    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate, challengeResult: makePiThreeDSResult()),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );

    $state = (fn () => $this->createSnapshotState())->call($aggregate);
    $restored = (fn () => PaymentIntentAggregate::reconstituteFromSnapshotState($id, $state))->call($aggregate);

    expect($restored->status())->toBe(PaymentIntentStatus::Charged)
        ->and($restored->captureMethod())->toBe(CaptureMethod::Immediate)
        ->and($restored->challengeResult())->toBeInstanceOf(ThreeDSResult::class);

    then();
});

it('snapshot roundtrip preserves processed and failed refunds', function () {
    $id = PaymentIntentId::generate();
    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    $processedId = RefundId::generate();
    $failedId = RefundId::generate();
    $partial = new Money(300, new Currency('USD'));
    $aggregate->refund(makeCreateRefundCommand($processedId, $partial), makeRefundSuccessPort());
    $aggregate->refund(makeCreateRefundCommand($failedId, $partial), makeRefundDeclinedPort('issuer_unavailable'));

    $state = (fn () => $this->createSnapshotState())->call($aggregate);
    $restored = (fn () => PaymentIntentAggregate::reconstituteFromSnapshotState($id, $state))->call($aggregate);

    $refunds = $restored->refunds();
    expect($refunds)->toHaveCount(2)
        ->and($refunds[$processedId->toString()]->status())->toBe(RefundStatus::Processed)
        ->and($refunds[$failedId->toString()]->status())->toBe(RefundStatus::Failed)
        ->and($restored->refundableAmount()->getAmount())->toBe('700');

    // Restored aggregate keeps invariant — failed refund didn't consume budget.
    $newRefundId = RefundId::generate();
    $restored->refund(
        makeCreateRefundCommand($newRefundId, new Money(700, new Currency('USD'))),
        makeRefundSuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );
    expect($restored->refundableAmount()->getAmount())->toBe('0');

    then();
});

it('throws InvalidRefund::currencyMismatch when refund currency differs from PI', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentCharged(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Immediate,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(
        makeCreateRefundCommand(RefundId::generate(), new Money(100, new Currency('EUR'))),
        makeRefundSuccessPort(),
    );
})->throws(InvalidRefund::class, 'does not match payment intent currency');

it('snapshot roundtrip preserves cancelled state', function () {
    $id = PaymentIntentId::generate();
    $instrument = makeInstrument();

    $state = [
        'status' => PaymentIntentStatus::Cancelled->value,
        'amount' => '500',
        'currency' => 'USD',
        'refundable_amount' => '500',
        'refundable_currency' => 'USD',
        'instrument' => $instrument->toPayload(),
        'capture_method' => CaptureMethod::Automatic->value,
        'metadata' => [],
        'billing_address' => [
            'first_name' => 'Test',
            'last_name' => 'User',
            'line' => '456 Elm St',
            'city' => 'LA',
            'country' => 'US',
            'postal_code' => '90001',
        ],
    ];

    $restored = new ReflectionMethod(PaymentIntentAggregate::class, 'reconstituteFromSnapshotState')
        ->invoke(null, $id, $state);

    expect($restored->status())->toBe(PaymentIntentStatus::Cancelled)
        ->and($restored->billingAddress()->city)->toBe('LA');

    then();
});

// ──────────────────────────────────────────────
//  Exception messages
// ──────────────────────────────────────────────

it('InvalidPaymentIntent::nonPositiveAmount returns correct message', function () {
    expect(InvalidPaymentIntent::nonPositiveAmount()->getMessage())
        ->toBe('Payment intent amount must be positive.');
});

it('PaymentIntentCannotBeCaptured::immediate returns correct message', function () {
    expect(PaymentIntentCannotBeCaptured::immediate()->getMessage())
        ->toBe('PaymentIntent capture_method is immediate; capture happens inline at create.');
});

it('fails the payment without touching the gateway when a rule rejects it', function () {
    // Was "parks for authentication ... when the firewall refuses", asserting a ThreeDSChallenge
    // the domain had built out of the payment intent's own id: every rendering field null, so no
    // client could act on it, and `transactionId()` handing out an id that reads like an
    // authentication reference and is not one.
    //
    // A denial is also not a step-up. Authentication cannot answer a rule that decided this
    // payment must not happen, so parking it would leave an intent waiting on evidence that could
    // never satisfy the rule. The reason is prefixed so it cannot be mistaken for an acquirer's
    // answer — nothing left the process and no issuer was consulted.
    $id = $this->aggregateRootId();

    $gateway = Mockery::mock(CreatePort::class);
    $gateway->shouldNotReceive('create');

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id),
        $gateway,
        StubPaymentIntentFirewall::denying(),
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::Failed)
        ->and($aggregate->challenge())->toBeNull();
});

it('refuses a payment that needs authentication and brought none', function () {
    // The whole shape of the server-to-server path. A chain demands a step-up; there is no
    // cardholder session here to conduct one in — no browser to fingerprint, nothing to render an
    // ACS page into, and on a stored instrument no pan the caller could authenticate with anyway.
    // So the payment ends now rather than being held open on an authentication that cannot start.
    //
    // No ChallengePort is needed for this: nothing is being verified. That matters, because it
    // means an installation without one still refuses correctly rather than throwing.
    $gateway = Mockery::mock(CreatePort::class);
    $gateway->shouldNotReceive('create');

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($this->aggregateRootId()),
        $gateway,
        StubPaymentIntentFirewall::returning(FirewallDecision::challenge('matched rule 9')),
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::Failed)
        ->and($aggregate->challenge())->toBeNull();
});

it('says authentication_required in a code, not only in a sentence', function () {
    // The difference between a caller that can act and one that matches our prose with a string.
    // "Do 3DS and send this again" and "the issuer refused, stop" are different instructions, and
    // the reason text is written for an operator, gets edited, and is sometimes the acquirer's
    // words rather than ours.
    //
    // The reason still carries the rule that asked, because a merchant retrying forever and a
    // merchant fixing a rule need to be able to tell which is happening.
    $recorded = null;

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($this->aggregateRootId()),
        Mockery::mock(CreatePort::class),
        StubPaymentIntentFirewall::returning(FirewallDecision::challenge('matched rule 9')),
    );

    foreach ($aggregate->releaseEvents() as $event) {
        if ($event instanceof PaymentIntentFailed) {
            $recorded = $event;
        }
    }

    expect($recorded?->code)->toBe(FailureCode::AuthenticationRequired)
        ->and($recorded?->reason)->toContain('matched rule 9');
});

it('weighs a presented authentication instead of taking it', function () {
    // The step that keeps this from being a bypass. Presenting a result is what carries a payment
    // past a step-up rule, and a well-formed result is indistinguishable from an invented one by
    // looking at it — so it is checked against the authentications this service issued, which is
    // why the port is handed the card and the amount rather than only the result.
    $presented = makePiThreeDSResult();
    $resolved = makePiThreeDSResult();

    $challenges = StubChallengePort::passing($resolved);
    $command = makeCreatePiCommand($this->aggregateRootId(), CaptureMethod::Immediate, challengeResult: $presented);

    $aggregate = PaymentIntentAggregate::create(
        $command,
        makePaySuccessPort(),
        StubPaymentIntentFirewall::returning(FirewallDecision::challenge('matched rule 9')),
        $challenges,
    );

    expect($challenges->verified?->presented)->toBe($presented)
        ->and($challenges->verified?->instrument)->toBe($command->instrument())
        ->and($challenges->verified?->amount)->toEqual($command->amount())
        ->and($challenges->verified?->paymentIntentId->toString())->toBe($command->paymentIntentId()->toString())
        ->and($aggregate->status())->toBe(PaymentIntentStatus::Charged);
});

it('carries the provider answer to the acquirer, not the caller copy of it', function () {
    // Asking is pointless if the reply is the question. What claims the liability shift is what
    // the record says, which may differ from what was handed in.
    $resolved = makePiThreeDSResult();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($this->aggregateRootId(), CaptureMethod::Immediate, challengeResult: makePiThreeDSResult()),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::returning(FirewallDecision::challenge('matched rule 9')),
        StubChallengePort::passing($resolved),
    );

    expect($aggregate->challengeResult())->toBe($resolved);
});

it('fails a payment whose presented authentication does not hold up', function () {
    // Spent already, issued for another card or another amount, or never issued by us at all —
    // the caller is told one thing, because saying which would tell someone probing us what to
    // fix.
    $gateway = Mockery::mock(CreatePort::class);
    $gateway->shouldNotReceive('create');

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($this->aggregateRootId(), CaptureMethod::Immediate, challengeResult: makePiThreeDSResult()),
        $gateway,
        StubPaymentIntentFirewall::returning(FirewallDecision::challenge('matched rule 9')),
        StubChallengePort::refusing('already spent'),
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::Failed);
});

it('refuses to weigh evidence with nothing installed to weigh it against', function () {
    // The one case that throws rather than failing the payment: a result was presented, so
    // something has to check it, and there is nothing. Accepting it unchecked would make every
    // step-up rule optional; failing the payment would blame a caller for a wiring mistake.
    expect(fn () => PaymentIntentAggregate::create(
        makeCreatePiCommand($this->aggregateRootId(), CaptureMethod::Immediate, challengeResult: makePiThreeDSResult()),
        Mockery::mock(CreatePort::class),
        StubPaymentIntentFirewall::returning(FirewallDecision::challenge('matched rule 9')),
    ))->toThrow(ChallengeCannotBeRaised::class, 'matched rule 9');
});

it('passes the domain data it holds to the firewall', function () {
    $id = $this->aggregateRootId();
    $firewall = StubPaymentIntentFirewall::allowing();

    PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate),
        makePaySuccessPort(),
        $firewall,
    );

    expect($firewall->received?->paymentIntentId?->toString())->toBe($id->toString())
        ->and($firewall->received?->amount)->toEqual(makeAmount());
});

it('consults the firewall on a merchant-initiated payment, which it used to skip', function () {
    // The skip reasoned that nobody is present to answer a step-up, which is true and was never
    // the whole question. A rule that decided this payment must not happen says nothing about who
    // is present, so skipping meant every deny rule in the chain went unasked on exactly the
    // traffic nobody is watching.
    $id = $this->aggregateRootId();
    $firewall = StubPaymentIntentFirewall::denying();

    $gateway = Mockery::mock(CreatePort::class);
    $gateway->shouldNotReceive('create');

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate, initiation: PaymentInitiation::MerchantRecurring),
        $gateway,
        $firewall,
    );

    expect($firewall->received)->not->toBeNull()
        ->and($firewall->received?->initiation)->toBe(PaymentInitiation::MerchantRecurring)
        ->and($aggregate->status())->toBe(PaymentIntentStatus::Failed);
});

it('refuses a step-up demanded of a payment with no cardholder to answer it', function () {
    // The part of the old skip that was right, kept where it belongs. Inspection happens; what
    // cannot happen is the authentication, and the port says so rather than the chain going
    // unconsulted. A chain that should not ask this of unattended traffic scopes its rules on the
    // `payment_intent.initiation` fact, which is why that fact now reaches it.
    $gateway = Mockery::mock(CreatePort::class);
    $gateway->shouldNotReceive('create');

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($this->aggregateRootId(), CaptureMethod::Immediate, initiation: PaymentInitiation::MerchantRecurring),
        $gateway,
        StubPaymentIntentFirewall::returning(FirewallDecision::challenge('step up')),
        StubChallengePort::refusing('no cardholder present'),
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::Failed);
});

it('consults the firewall even when a finished authentication came with the payment', function (ThreeDSStatus $status) {
    // The bypass this closed, and it was reachable by anyone: the result arrives in the create
    // command, the coherence check on it establishes that its fields agree with each other and
    // nothing about whether an issuer ever saw the cardholder — so attaching a well-formed one
    // walked past every deny rule in the chain.
    //
    // Info is in the dataset because it was the cheapest way through: a data-share-only result
    // counts as a success without any cardholder having been challenged at all.
    $id = $this->aggregateRootId();
    $firewall = StubPaymentIntentFirewall::denying();

    $gateway = Mockery::mock(CreatePort::class);
    $gateway->shouldNotReceive('create');

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Immediate, challengeResult: makePiThreeDSResult($status)),
        $gateway,
        $firewall,
    );

    expect($firewall->received)->not->toBeNull()
        ->and($aggregate->status())->toBe(PaymentIntentStatus::Failed);
})->with([
    'authenticated' => ThreeDSStatus::Successful,
    'data share only' => ThreeDSStatus::Info,
]);

it('lets an allowed payment reach the gateway, whether a rule said so or the chain fell through', function (bool $matched) {
    // Replaces "refuses an accept whose chain was degraded". That case is gone from here entirely:
    // a chain that cannot be fully evaluated now throws instead of returning an accept with a flag
    // on it, so there is no partly-evaluated accept for the domain to second-guess. What is left
    // to state is that both kinds of allow proceed — one a rule granted, one nothing objected to.
    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($this->aggregateRootId(), CaptureMethod::Immediate),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::returning(FirewallDecision::allow('stub', matched: $matched)),
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::Charged);
})->with(['by a rule' => [true], 'by fallthrough' => [false]]);

// ──────────────────────────────────────────────
//  what a capture tells the port about the hold
//
//  ConnexPay can only capture the full authorized amount; its documented procedure
//  for anything less is to void and re-sell, which needs both the original amount (to
//  notice a partial request at all) and a card (to sell against). Neither is reachable
//  from its side — an authorization has no lookup endpoint and the card on a sale
//  record is masked — so the request has to carry them. Its adapter's own words for
//  what happens otherwise: "the full hold would be captured silently".
// ──────────────────────────────────────────────

function makeCaptureCapturingPort(?CaptureRequest &$seen): CapturePort
{
    return new class($seen) implements CapturePort
    {
        public function __construct(private ?CaptureRequest &$seen) {}

        public function capture(CaptureRequest $request): CaptureOutcome
        {
            $this->seen = $request;

            return new CaptureOutcome;
        }
    };
}

it('tells the port what was held and what it was held on, not just what to capture', function () {
    $id = PaymentIntentId::generate();
    $authorized = makeAmount();
    $instrument = makeInstrument();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Manual, amount: $authorized, instrument: $instrument),
        makePaySuccessPort(),
        StubPaymentIntentFirewall::allowing(),
    );

    $seen = null;
    $partial = $authorized->divide(2);
    $aggregate->capture(makeCapturePiCommand($id, $partial), makeCaptureCapturingPort($seen));

    expect($seen->amount)->toBe($partial)
        // From the intent's own state, so a caller cannot restate the hold as something
        // it was not: only `amount` is the caller's to choose.
        ->and($seen->authorizedAmount)->toBe($authorized)
        ->and($seen->instrument)->toBe($instrument);
});

// ──────────────────────────────────────────────
//  a result must carry what makes it successful
//
//  Status alone used to decide: `Y` with no authentication value passed as a success,
//  and the aggregate charged on it, storing as the evidence for the liability shift an
//  artefact that proves nothing. The two call sites want opposite answers, which is why
//  the coherence question is asked separately from the status one.
// ──────────────────────────────────────────────

it('refuses to confirm a challenge on a success status with no authentication value', function (ThreeDSStatus $status) {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);

    // Not PaymentIntentFailed: nobody refused anything. Recording a decline here would
    // tell operators the issuer said no to an authentication it never answered.
    expect(fn () => $aggregate->confirmChallenge(
        new ThreeDSResult($status, null, ECICode::VisaSuccessful, 'ds-txn', 'acs-txn'),
        makeExternallyCompletedConfirmPort(),
    ))->toThrow(InvalidPaymentIntent::class, 'without an authentication value');
})->with([
    ThreeDSStatus::Successful,
    ThreeDSStatus::NotAvailable,
    ThreeDSStatus::Info,
]);

it('still records a failure when the issuer actually refused, cryptogram or not', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentRequiresAction(
        makeAmount(),
        makeInstrument(),
        CaptureMethod::Automatic,
        makeBillingAddress(),
        [],
        makeMerchantDescriptor(),
        '',
        makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    // A refusal carries no authentication value either, and that is what a refusal looks
    // like — the coherence check must not swallow it.
    $aggregate->confirmChallenge(
        new ThreeDSResult(ThreeDSStatus::NotAuthenticated, null, null, 'ds-txn', 'acs-txn'),
        makeExternallyCompletedConfirmPort(),
    );

    expect($aggregate->status())->toBe(PaymentIntentStatus::Failed);
});

it('inspects rather than throwing when an incoherent result arrives with the payment', function () {
    // The other call site, and the opposite answer. `confirmChallenge()` throws on a result that
    // claims success while carrying no cryptogram, because there it is the whole basis for
    // completing a payment. At `create()` it is a claim the caller made, so it goes to the
    // firewall and then to verification like any other — a caller's malformed evidence must not
    // become a failure to create the payment at all.
    //
    // The port refuses it here, which is what an implementation checking against its own records
    // would do with a result it never issued.
    $firewall = StubPaymentIntentFirewall::returning(FirewallDecision::challenge('stub'));

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand(
            PaymentIntentId::generate(),
            CaptureMethod::Immediate,
            challengeResult: new ThreeDSResult(ThreeDSStatus::Successful, null, null, 'ds-txn', 'acs-txn'),
        ),
        makePaySuccessPort(),
        $firewall,
        StubChallengePort::refusing('no such authentication'),
    );

    expect($firewall->received)->not->toBeNull()
        ->and($aggregate->status())->toBe(PaymentIntentStatus::Failed);
});
