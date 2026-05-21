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
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
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
use Techork\PaymentService\Domain\PaymentIntent\Exception\InvalidPaymentIntent;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentCannotBeCancelled;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentCannotBeCaptured;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentCannotBeRefunded;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentChallengeNotPending;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentRefundExceedsAmount;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CancelRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\CancelPort;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Command\CreateRefundCommand;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Command\RecordRefundFeeCommand;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundFailed;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundFeeRecorded;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundImported;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundProcessed;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Exception\RefundNotFound;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\RefundPort;
use Techork\PaymentService\Domain\PaymentIntent\Refund\RefundStatus;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Tests\Support\PaymentIntentAggregateTestCase;
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
        acsUrl: 'https://acs.example.com/challenge',
        transactionId: 'gw-txn-123',
        creq: 'base64-creq',
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

function makeCreatePiCommand(
    PaymentIntentId $id,
    CaptureMethod $captureMethod = CaptureMethod::Automatic,
    ?Money $amount = null,
    ?PaymentInstrument $instrument = null,
    ?ChallengeResult $challengeResult = null,
): CreatePaymentIntentCommand {
    return new readonly class($id, $captureMethod, $amount ?? makeAmount(), $instrument ?? makeInstrument(), $challengeResult) implements CreatePaymentIntentCommand
    {
        public function __construct(
            private PaymentIntentId $paymentIntentId,
            private CaptureMethod $captureMethod,
            private Money $amount,
            private PaymentInstrument $instrument,
            private ?ChallengeResult $challengeResult,
        ) {}

        public function paymentIntentId(): PaymentIntentId { return $this->paymentIntentId; }
        public function amount(): Money { return $this->amount; }
        public function instrument(): PaymentInstrument { return $this->instrument; }
        public function captureMethod(): CaptureMethod { return $this->captureMethod; }
        public function billingAddress(): BillingAddress { return makeBillingAddress(); }
        public function metadata(): array { return []; }
        public function challengeResult(): ?ChallengeResult { return $this->challengeResult; }
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

function makePaySuccessPort(): CreatePort
{
    return new readonly class implements CreatePort
    {
        public function create(CreateRequest $request): ?Challenge { return null; }
    };
}

function makePayChallengePort(Challenge $challenge): CreatePort
{
    return new readonly class($challenge) implements CreatePort
    {
        public function __construct(private Challenge $challenge) {}
        public function create(CreateRequest $request): ?Challenge { return $this->challenge; }
    };
}

function makePayDeclinedPort(string $reason): CreatePort
{
    return new readonly class($reason) implements CreatePort
    {
        public function __construct(private string $reason) {}
        public function create(CreateRequest $request): ?Challenge { throw new GatewayDeclinedException($this->reason); }
    };
}

function makeCaptureSuccessPort(): CapturePort
{
    return new readonly class implements CapturePort
    {
        public function capture(CaptureRequest $request): void {}
    };
}

function makeCaptureDeclinedPort(string $reason): CapturePort
{
    return new readonly class($reason) implements CapturePort
    {
        public function __construct(private string $reason) {}
        public function capture(CaptureRequest $request): void { throw new GatewayDeclinedException($this->reason); }
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
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCharged(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
    ));
});

it('records PaymentIntentAuthorized on create with Automatic + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Automatic),
        makePaySuccessPort(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [],
    ));
});

it('records PaymentIntentAuthorized on create with Manual + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Manual),
        makePaySuccessPort(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [],
    ));
});

// ──────────────────────────────────────────────
//  Create — gateway non-success branches
// ──────────────────────────────────────────────

it('records PaymentIntentFailed on create with GatewayDeclined', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Automatic),
        makePayDeclinedPort('insufficient_funds'),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], 'insufficient_funds',
    ));
});

it('records PaymentIntentRequiresAction on create with GatewayChallengeRequired', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Automatic),
        makePayChallengePort(makeThreeDSChallenge()),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentRequiresAction(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], makeThreeDSChallenge(),
    ));
});

it('forwards pre-auth ChallengeResult into the initial event', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $preAuth = makePiThreeDSResult();

    $aggregate = PaymentIntentAggregate::create(
        makeCreatePiCommand($id, CaptureMethod::Automatic, challengeResult: $preAuth),
        makePaySuccessPort(),
    );
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], $preAuth,
    ));
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
    );
})->throws(InvalidPaymentIntent::class, 'Payment intent amount must be positive.');

it('throws InvalidPaymentIntent for negative amount', function () {
    $id = $this->aggregateRootId();
    $negative = new Money(-100, new Currency('USD'));

    PaymentIntentAggregate::create(
        makeCreatePiCommand($id, amount: $negative),
        makePaySuccessPort(),
    );
})->throws(InvalidPaymentIntent::class, 'Payment intent amount must be positive.');

it('throws InvalidPaymentIntent for unusable instrument', function () {
    $id = $this->aggregateRootId();

    PaymentIntentAggregate::create(
        makeCreatePiCommand($id, instrument: makeUnusableInstrument()),
        makePaySuccessPort(),
    );
})->throws(InvalidPaymentIntent::class, 'Payment source is not usable');

// ──────────────────────────────────────────────
//  confirmChallenge — success branches
// ──────────────────────────────────────────────

it('records PaymentIntentAuthorized on confirmChallenge after RequiresAction with Automatic', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentRequiresAction(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge(makePiThreeDSResult());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], makePiThreeDSResult(),
    ));
});

it('records PaymentIntentCharged on confirmChallenge after RequiresAction with Immediate', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentRequiresAction(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [], makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge(makePiThreeDSResult());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCharged(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [], makePiThreeDSResult(),
    ));
});

it('treats ThreeDSStatus::NotAvailable as success (liability shift)', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = makePiThreeDSResult(ThreeDSStatus::NotAvailable);

    given(new PaymentIntentRequiresAction(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [], makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result);
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [], $result,
    ));
});

it('treats RedirectResult as success', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = new RedirectResult(transactionId: 'pay-77');

    given(new PaymentIntentRequiresAction(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], makeRedirectChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result);
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], $result,
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
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result);
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], '3DS authentication: N', $result,
    ));
});

it('records PaymentIntentFailed on confirmChallenge with Rejected', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $result = makePiThreeDSResult(ThreeDSStatus::Rejected);

    given(new PaymentIntentRequiresAction(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [], makeThreeDSChallenge(),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge($result);
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [], '3DS authentication: R', $result,
    ));
});

it('throws PaymentIntentChallengeNotPending when confirmChallenge called outside RequiresAction', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentCharged(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmChallenge(makePiThreeDSResult());
})->throws(PaymentIntentChallengeNotPending::class);

// ──────────────────────────────────────────────
//  Capture — through CapturePort
// ──────────────────────────────────────────────

it('records PaymentIntentCaptured on capture from Authorized + GatewaySuccess', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [],
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->capture(makeCapturePiCommand($id), makeCaptureSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCaptured(makeAmount()));
});

it('records PaymentIntentCaptured with partial amount', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();
    $partial = new Money(500, new Currency('USD'));

    given(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [],
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->capture(makeCapturePiCommand($id, $partial), makeCaptureSuccessPort());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentCaptured($partial));
});

it('records PaymentIntentFailed on capture + GatewayDeclined', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [],
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->capture(makeCapturePiCommand($id), makeCaptureDeclinedPort('issuer_unavailable'));
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [], 'issuer_unavailable',
    ));
});

it('throws PaymentIntentCannotBeCaptured on capture from Charged', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentCharged(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
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
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [],
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
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], makeThreeDSChallenge(),
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
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [],
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelPiCommand($id), makeVoidDeclinedPort('void_not_allowed'));
    $this->persistAggregateRoot($aggregate);

    then(new PaymentIntentFailed(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [], 'void_not_allowed',
    ));
});

it('throws PaymentIntentCannotBeCancelled when already Charged', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentCharged(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelPiCommand($id), makeVoidSuccessPort());
})->throws(PaymentIntentCannotBeCancelled::class);

it('throws PaymentIntentCannotBeCancelled when already Cancelled', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(
        new PaymentIntentAuthorized(makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), []),
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
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
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
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
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
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
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
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
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
        new PaymentIntentCharged(makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), []),
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
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
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
        new PaymentIntentCharged(makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), []),
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
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(makeCreateRefundCommand(RefundId::generate(), $tooMuch), makeRefundSuccessPort());
})->throws(PaymentIntentRefundExceedsAmount::class);

it('throws PaymentIntentCannotBeRefunded when not Charged (Authorized)', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentAuthorized(
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), [],
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
        new PaymentIntentCharged(makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), []),
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
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
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

it('applies PaymentIntentImported and allows refund up to the imported amount', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentImported(
        amount: new Money(2000, new Currency('EUR')),
        status: PaymentIntentStatus::Charged,
        instrument: makeImportedPaymentMethod(),
        captureMethod: CaptureMethod::Automatic,
        billingAddress: makeBillingAddress(),
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
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
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
        makeAmount(), makeInstrument(), CaptureMethod::Manual, makeBillingAddress(), ['k' => 'v'], makePiThreeDSResult(),
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
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
    );
    $restored = PaymentIntentCharged::fromPayload($event->toPayload());

    expect($restored->amount->getAmount())->toBe('1000')
        ->and($restored->captureMethod)->toBe(CaptureMethod::Immediate)
        ->and($restored->challengeResult)->toBeNull();

    then();
});

it('PaymentIntentRequiresAction survives serialization roundtrip (3DS)', function () {
    $event = new PaymentIntentRequiresAction(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], makeThreeDSChallenge(),
    );
    $restored = PaymentIntentRequiresAction::fromPayload($event->toPayload());

    expect($restored->challenge)->toBeInstanceOf(ThreeDSChallenge::class)
        ->and($restored->challenge->acsUrl)->toBe('https://acs.example.com/challenge');

    then();
});

it('PaymentIntentRequiresAction survives serialization roundtrip (Redirect)', function () {
    $event = new PaymentIntentRequiresAction(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], makeRedirectChallenge(),
    );
    $restored = PaymentIntentRequiresAction::fromPayload($event->toPayload());

    expect($restored->challenge)->toBeInstanceOf(RedirectChallenge::class)
        ->and($restored->challenge->url)->toBe('https://hosted.example/checkout');

    then();
});

it('PaymentIntentFailed survives serialization roundtrip', function () {
    $event = new PaymentIntentFailed(
        makeAmount(), makeInstrument(), CaptureMethod::Automatic, makeBillingAddress(), [], 'card_declined', makePiThreeDSResult(),
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
    );

    $restored = PaymentIntentImported::fromPayload($event->toPayload());

    expect($restored->amount->getAmount())->toBe('3000')
        ->and($restored->status)->toBe(PaymentIntentStatus::Charged)
        ->and((string) $restored->billingAddress->state)->toBe('NY');

    then();
});

it('imports a hosted-flow PaymentIntent with no billing address', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentImported(
        amount: new Money(2000, new Currency('EUR')),
        status: PaymentIntentStatus::Charged,
        instrument: new HostedPayment('', ''),
        captureMethod: CaptureMethod::Automatic,
        billingAddress: null,
    ));

    $aggregate = $this->retrieveAggregateRoot($id);

    expect($aggregate->billingAddress())->toBeNull()
        ->and($aggregate->instrument())->toBeInstanceOf(HostedPayment::class);

    then();
});

it('hosted-flow PaymentIntentImported survives serialization roundtrip', function () {
    $event = new PaymentIntentImported(
        amount: new Money(3000, new Currency('GBP')),
        status: PaymentIntentStatus::Charged,
        instrument: new HostedPayment('', ''),
        captureMethod: CaptureMethod::Automatic,
        billingAddress: null,
    );

    $restored = PaymentIntentImported::fromPayload($event->toPayload());

    expect($restored->billingAddress)->toBeNull()
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
    );
    expect($restored->refundableAmount()->getAmount())->toBe('0');

    then();
});

it('throws InvalidRefund::currencyMismatch when refund currency differs from PI', function () {
    /** @var PaymentIntentId $id */
    $id = $this->aggregateRootId();

    given(new PaymentIntentCharged(
        makeAmount(), makeInstrument(), CaptureMethod::Immediate, makeBillingAddress(), [],
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->refund(
        makeCreateRefundCommand(RefundId::generate(), new Money(100, new Currency('EUR'))),
        makeRefundSuccessPort(),
    );
})->throws(\Techork\PaymentService\Domain\PaymentIntent\Refund\Exception\InvalidRefund::class, 'does not match payment intent currency');

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
