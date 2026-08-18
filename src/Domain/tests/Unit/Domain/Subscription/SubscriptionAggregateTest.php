<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Tests\Support\StubPaymentIntentFirewall;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\Command\CreatePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\Command\CapturePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\Port\CaptureOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreateOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Domain\Subscription\Command\ActivateSubscriptionCommand;
use Techork\PaymentService\Domain\Subscription\Command\CancelSubscriptionCommand;
use Techork\PaymentService\Domain\Subscription\ValueObject\CancellationTiming;
use Techork\PaymentService\Domain\Subscription\Command\CreateSubscriptionCommand;
use Techork\PaymentService\Domain\Subscription\Command\RecordSubscriptionRenewalCommand;
use Techork\PaymentService\Domain\Subscription\Command\RevertSubscriptionCancellationCommand;
use Techork\PaymentService\Domain\Subscription\Event\SubscriptionActivated;
use Techork\PaymentService\Domain\Subscription\Event\SubscriptionCancellationReverted;
use Techork\PaymentService\Domain\Subscription\Event\SubscriptionCancelled;
use Techork\PaymentService\Domain\Subscription\Event\SubscriptionCreated;
use Techork\PaymentService\Domain\Subscription\Event\SubscriptionRenewed;
use Techork\PaymentService\Domain\Subscription\Exception\SubscriptionNotActivatable;
use Techork\PaymentService\Domain\Subscription\Exception\SubscriptionNotCancellable;
use Techork\PaymentService\Domain\Subscription\Exception\SubscriptionNotRenewable;
use Techork\PaymentService\Domain\Subscription\Port\Request\SubscriptionCaptureRequest;
use Techork\PaymentService\Domain\Subscription\Port\SubscriptionCapturePort;
use Techork\PaymentService\Domain\Subscription\SubscriptionAggregate;
use Techork\PaymentService\Domain\Subscription\SubscriptionStatus;
use Techork\PaymentService\Domain\Subscription\ValueObject\BillingInterval;
use Techork\PaymentService\Domain\Subscription\ValueObject\BillingPeriod;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionPlan;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Tests\Support\SubscriptionAggregateTestCase;
use function EventSauce\EventSourcing\PestTooling\given;
use function EventSauce\EventSourcing\PestTooling\then;
use function EventSauce\EventSourcing\PestTooling\when;

uses(SubscriptionAggregateTestCase::class);

// ──────────────────────────────────────────────
//  Helpers
// ──────────────────────────────────────────────

function makeSubscriptionAmount(): Money
{
    return new Money(2999, new Currency('USD'));
}

function makeSubscriptionPaymentMethodId(): PaymentMethodId
{
    return PaymentMethodId::fromString('00000000-0000-0000-0000-000000000088');
}

function makeSubscriptionPlan(?Money $amount = null): SubscriptionPlan
{
    return new SubscriptionPlan(
        $amount ?? makeSubscriptionAmount(),
        new BillingInterval(1, BillingPeriod::Month),
    );
}

function makeSubscriptionPaymentIntentId(): PaymentIntentId
{
    return PaymentIntentId::fromString('00000000-0000-0000-0000-000000000099');
}

function makeCreateSubscriptionCommand(SubscriptionId $id, ?SubscriptionPlan $plan = null): CreateSubscriptionCommand
{
    return new readonly class($id, $plan ?? makeSubscriptionPlan()) implements CreateSubscriptionCommand
    {
        public function __construct(private SubscriptionId $subscriptionId, private SubscriptionPlan $plan) {}

        public function subscriptionId(): SubscriptionId
        {
            return $this->subscriptionId;
        }

        public function plan(): SubscriptionPlan
        {
            return $this->plan;
        }

        public function paymentMethodId(): PaymentMethodId
        {
            return makeSubscriptionPaymentMethodId();
        }

        public function callbackUrl(): ?string
        {
            return 'https://example.com/webhook';
        }

        public function metadata(): array
        {
            return ['tier' => 'pro'];
        }
    };
}

/**
 * An intent activation can still act on: authorized, not charged. A capture
 * method other than Immediate is what leaves it that way, and it is also what
 * `PaymentIntentAggregate::capture()` requires.
 */
function makeAuthorizedPiForSubscription(?Money $amount = null): PaymentIntentAggregate
{
    $piId = makeSubscriptionPaymentIntentId();
    $piAmount = $amount ?? makeSubscriptionAmount();

    $cmd = new readonly class($piId, $piAmount) implements CreatePaymentIntentCommand
    {
        public function __construct(private PaymentIntentId $id, private Money $amount) {}

        public function paymentIntentId(): PaymentIntentId { return $this->id; }
        public function amount(): Money { return $this->amount; }
        public function instrument(): PaymentInstrument
        {
            return new PaymentMethod(
                PaymentMethodId::fromString('01961f5a-0000-7000-8000-000000000002'),
                new CreditCard(
                    new Number('424242', '4242', CardBrand::Visa),
                    Expiration::fromMonthAndYear(12, 2030),
                    new Holder('Test'),
                    new Cvc,
                ),
                new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001'),
            );
        }
        public function captureMethod(): CaptureMethod { return CaptureMethod::Automatic; }
        public function billingAddress(): BillingAddress
        {
            return new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001');
        }
        public function merchantDescriptor(): MerchantDescriptor { return new MerchantDescriptor('SUBSCRIPTION'); }
        public function description(): string { return ''; }
        public function metadata(): array { return []; }
        public function challengeResult(): ?ChallengeResult { return null; }
        public function initiation(): PaymentInitiation { return PaymentInitiation::CardholderInitiated; }
        public function connection(): ?ConnectionContext { return null; }
        public function gatewayId(): ?string { return null; }
    };

    return PaymentIntentAggregate::create($cmd, makeSubscriptionPiSuccessPort(), StubPaymentIntentFirewall::allowing());
}

function makeSubscriptionPiSuccessPort(): CreatePort
{
    return new readonly class implements CreatePort
    {
        public function create(CreateRequest $request): CreateOutcome { return new CreateOutcome(); }
    };
}

function makeActivationPeriodStart(): DateTimeImmutable
{
    // Far enough into the future that a pending cancellation never gets resolved
    // to Cancelled by the computed status() during a test run.
    return new DateTimeImmutable('2099-01-01T00:00:00+00:00');
}

function makeActivateCommand(SubscriptionId $id, ?PaymentIntentAggregate $pi = null): ActivateSubscriptionCommand
{
    $pi ??= makeAuthorizedPiForSubscription();

    return new readonly class($id, $pi) implements ActivateSubscriptionCommand
    {
        public function __construct(private SubscriptionId $subscriptionId, private PaymentIntentAggregate $pi) {}

        public function subscriptionId(): SubscriptionId { return $this->subscriptionId; }
        public function periodStart(): DateTimeImmutable { return makeActivationPeriodStart(); }
        public function paymentIntent(): PaymentIntentAggregate { return $this->pi; }
    };
}

function makeSubscriptionCapturePort(): SubscriptionCapturePort
{
    return new readonly class implements SubscriptionCapturePort
    {
        public function capture(SubscriptionCaptureRequest $request): void {}
    };
}

/**
 * The only way a capture fails: it throws. There is no declined outcome to
 * return, so an adapter with a broken connection surfaces exactly this.
 */
function makeFailingSubscriptionCapturePort(string $reason = 'Connection to gateway lost'): SubscriptionCapturePort
{
    return new readonly class($reason) implements SubscriptionCapturePort
    {
        public function __construct(private string $reason) {}

        public function capture(SubscriptionCaptureRequest $request): void
        {
            throw new RuntimeException($this->reason);
        }
    };
}

/**
 * Fails the test if the acquirer is reached — pins that every refusal activation
 * can decide for itself happens before the capture.
 */
function makeUntouchedSubscriptionCapturePort(): SubscriptionCapturePort
{
    return new readonly class implements SubscriptionCapturePort
    {
        public function capture(SubscriptionCaptureRequest $request): void
        {
            throw new RuntimeException('SubscriptionCapturePort must not be reached when a local check already refuses.');
        }
    };
}

/**
 * What a host implementation has to do: capture *through the payment intent
 * aggregate*, so the intent's own Authorized-only check is what rejects a second
 * activation. A port that went straight to the gateway would satisfy the type and
 * lose the guarantee, which is why this is the stub used wherever the guarantee
 * is under test.
 */
function makeIntentCapturingSubscriptionPort(PaymentIntentAggregate $paymentIntent): SubscriptionCapturePort
{
    return new readonly class($paymentIntent) implements SubscriptionCapturePort
    {
        public function __construct(private PaymentIntentAggregate $paymentIntent) {}

        public function capture(SubscriptionCaptureRequest $request): void
        {
            $command = new readonly class($request->paymentIntentId, $request->amount) implements CapturePaymentIntentCommand
            {
                public function __construct(private PaymentIntentId $id, private Money $amount) {}

                public function paymentIntentId(): PaymentIntentId { return $this->id; }

                public function amount(): Money { return $this->amount; }
            };

            $gatewayCapture = new readonly class implements CapturePort
            {
                public function capture(CaptureRequest $request): CaptureOutcome { return new CaptureOutcome; }
            };

            // Lets the intent's own refusal propagate: there is no declined
            // outcome to translate it into any more.
            $this->paymentIntent->capture($command, $gatewayCapture);
        }
    };
}

function makeRenewalCommand(SubscriptionId $id): RecordSubscriptionRenewalCommand
{
    return new readonly class($id) implements RecordSubscriptionRenewalCommand
    {
        public function __construct(private SubscriptionId $subscriptionId) {}

        public function subscriptionId(): SubscriptionId
        {
            return $this->subscriptionId;
        }

        public function periodStart(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-05-01T00:00:00+00:00');
        }
    };
}

function makeRevertCancellationCommand(SubscriptionId $id): RevertSubscriptionCancellationCommand
{
    return new readonly class($id) implements RevertSubscriptionCancellationCommand
    {
        public function __construct(private SubscriptionId $subscriptionId) {}

        public function subscriptionId(): SubscriptionId { return $this->subscriptionId; }
    };
}

function makeCancelCommand(
    SubscriptionId $id,
    string $reason = 'user_request',
    CancellationTiming $timing = CancellationTiming::AtPeriodEnd,
): CancelSubscriptionCommand {
    return new readonly class($id, $reason, $timing) implements CancelSubscriptionCommand
    {
        public function __construct(
            private SubscriptionId $subscriptionId,
            private string $reason,
            private CancellationTiming $timing,
        ) {}

        public function subscriptionId(): SubscriptionId { return $this->subscriptionId; }
        public function reason(): string { return $this->reason; }
        public function timing(): CancellationTiming { return $this->timing; }
    };
}


function makeSubscriptionCreated(?SubscriptionPlan $plan = null): SubscriptionCreated
{
    return new SubscriptionCreated(
        $plan ?? makeSubscriptionPlan(),
        makeSubscriptionPaymentMethodId(),
        'https://example.com/webhook',
        ['tier' => 'pro'],
    );
}

function makeSubscriptionActivated(): SubscriptionActivated
{
    // Period must end in the future, otherwise a pending cancellation gets resolved
    // to Cancelled by the computed status() and revert tests can't observe
    // the intermediate state.
    $start = makeActivationPeriodStart();

    return new SubscriptionActivated(
        makeSubscriptionPaymentIntentId(),
        $start,
        $start->modify('+1 month'),
    );
}

// ──────────────────────────────────────────────
//  Create
// ──────────────────────────────────────────────

it('records SubscriptionCreated on create', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    when(makeCreateSubscriptionCommand($id))
        ->then(makeSubscriptionCreated());
});

// ──────────────────────────────────────────────
//  Activate
// ──────────────────────────────────────────────

it('records SubscriptionActivated on activate from trialing with an authorized PI', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id), makeSubscriptionCapturePort());
    $this->persistAggregateRoot($aggregate);

    then(makeSubscriptionActivated());
});

it('captures the payment intent it is activated with', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();
    $paymentIntent = makeAuthorizedPiForSubscription();

    given(makeSubscriptionCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id, $paymentIntent), makeIntentCapturingSubscriptionPort($paymentIntent));
    $this->persistAggregateRoot($aggregate);

    // The first period's money moved as part of activating, not before it.
    expect($paymentIntent->status())->toBe(PaymentIntentStatus::Charged);

    then(makeSubscriptionActivated());
});

/**
 * The same mechanism as the checkout's: capture happens once, so a second
 * subscription has nothing left to take. The untouched port is the point — the
 * second activation is refused before the acquirer is reached, so this is not a
 * gateway rejecting a double charge, it is one never being requested.
 *
 * Sequential only. Two concurrent activations both read Authorized from their own
 * hydration; see SubscriptionCapturePort for why the domain cannot close that.
 */
it('refuses to activate two subscriptions with the same payment intent', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();
    $paymentIntent = makeAuthorizedPiForSubscription();

    $firstId = SubscriptionId::fromString('00000000-0000-0000-0000-0000000000bb');
    $first = SubscriptionAggregate::create(makeCreateSubscriptionCommand($firstId));
    $first->activate(makeActivateCommand($firstId, $paymentIntent), makeIntentCapturingSubscriptionPort($paymentIntent));

    given(makeSubscriptionCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id, $paymentIntent), makeUntouchedSubscriptionCapturePort());
})->throws(SubscriptionNotActivatable::class, 'requires an authorized payment intent (got [charged])');

/**
 * Capture has no declined branch to record, so a failure propagates untouched and
 * the subscription stays in the status it already had — which is what makes the
 * retry just the same call again.
 */
it('lets a failed capture propagate, staying trialing and recording nothing', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    $aggregate = $this->retrieveAggregateRoot($id);

    try {
        $aggregate->activate(makeActivateCommand($id), makeFailingSubscriptionCapturePort());
        $this->fail('the capture failure should have propagated');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Connection to gateway lost');
    }

    expect($aggregate->status())->toBe(SubscriptionStatus::Trialing);

    $this->persistAggregateRoot($aggregate);

    then();
});

/**
 * The inverse of what this asserted before activation started capturing for
 * itself. An `Immediate` intent moved the money at create, before any of the
 * activation checks ran, and the same one could then activate a second
 * subscription with nothing left to refuse. Now it is the unusable state.
 */
it('throws SubscriptionNotActivatable when the payment intent was already charged inline', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    // Build a Charged (Immediate capture) PI — nothing left to capture.
    $piId = makeSubscriptionPaymentIntentId();
    $piCmd = new readonly class($piId) implements CreatePaymentIntentCommand
    {
        public function __construct(private PaymentIntentId $id) {}
        public function paymentIntentId(): PaymentIntentId { return $this->id; }
        public function amount(): Money { return makeSubscriptionAmount(); }
        public function instrument(): PaymentInstrument
        {
            return new PaymentMethod(
                PaymentMethodId::fromString('01961f5a-0000-7000-8000-000000000002'),
                new CreditCard(
                    new Number('424242', '4242', CardBrand::Visa),
                    Expiration::fromMonthAndYear(12, 2030),
                    new Holder('Test'),
                    new Cvc,
                ),
                new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001'),
            );
        }
        public function captureMethod(): CaptureMethod { return CaptureMethod::Immediate; }
        public function billingAddress(): BillingAddress
        {
            return new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001');
        }
        public function merchantDescriptor(): MerchantDescriptor { return new MerchantDescriptor('SUBSCRIPTION'); }
        public function description(): string { return ''; }
        public function metadata(): array { return []; }
        public function challengeResult(): ?ChallengeResult { return null; }
        public function initiation(): PaymentInitiation { return PaymentInitiation::CardholderInitiated; }
        public function connection(): ?ConnectionContext { return null; }
        public function gatewayId(): ?string { return null; }
    };
    $pi = PaymentIntentAggregate::create($piCmd, makeSubscriptionPiSuccessPort(), StubPaymentIntentFirewall::allowing());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id, $pi), makeUntouchedSubscriptionCapturePort());
})->throws(SubscriptionNotActivatable::class, 'requires an authorized payment intent (got [charged])');

it('throws SubscriptionNotActivatable when payment intent amount does not match plan', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    $pi = makeAuthorizedPiForSubscription(new Money(9999, new Currency('USD')));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id, $pi), makeSubscriptionCapturePort());
})->throws(SubscriptionNotActivatable::class, 'does not match subscription plan amount');

it('throws SubscriptionNotActivatable when already active', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id), makeSubscriptionCapturePort());
})->throws(SubscriptionNotActivatable::class);

// ──────────────────────────────────────────────
//  Renew
// ──────────────────────────────────────────────

it('records SubscriptionRenewed on renew from active', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->renew(makeRenewalCommand($id));
    $this->persistAggregateRoot($aggregate);

    // periodEnd computed: 2026-05-01 + 1 month = 2026-06-01
    then(new SubscriptionRenewed(
        new DateTimeImmutable('2026-05-01T00:00:00+00:00'),
        new DateTimeImmutable('2026-06-01T00:00:00+00:00'),
    ));
});

it('throws SubscriptionNotRenewable when trialing', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->renew(makeRenewalCommand($id));
})->throws(SubscriptionNotRenewable::class);

it('throws SubscriptionNotRenewable when cancelled', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        // Trialing: no period to live out, so the cancellation was recorded as biting at once.
        new SubscriptionCancelled('user_request', new DateTimeImmutable('2020-01-01T00:00:00.000+00:00')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->renew(makeRenewalCommand($id));
})->throws(SubscriptionNotRenewable::class);

it('throws SubscriptionNotRenewable while a cancellation is pending', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
        new SubscriptionCancelled('user_request', makeActivationPeriodStart()->modify('+1 month')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->renew(makeRenewalCommand($id));
})->throws(SubscriptionNotRenewable::class, 'cancellation is pending');

it('records SubscriptionCancelled with payment-failure reason (auto-termination)', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelCommand($id, 'payment_failed'));
    $this->persistAggregateRoot($aggregate);

    then(new SubscriptionCancelled('payment_failed', makeActivationPeriodStart()->modify('+1 month')));
});

// ──────────────────────────────────────────────
//  Cancel — single event, computed terminal state
// ──────────────────────────────────────────────

it('records SubscriptionCancelled on cancel from active', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(new SubscriptionCancelled('user_request', makeActivationPeriodStart()->modify('+1 month')));
});

it('records SubscriptionCancelled on cancel from trialing', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelCommand($id));

    // No period to live out, so it bites now. The instant is minted inside the aggregate;
    // what this test is for is the effect of it.
    expect($aggregate->status())->toBe(SubscriptionStatus::Cancelled)
        ->and($aggregate->cancellationEffectiveAt())->not->toBeNull();

    $this->persistAggregateRoot($aggregate);
    then(new SubscriptionCancelled('user_request', $aggregate->cancellationEffectiveAt()));
});

it('keeps subscription Active after cancel until the period ends', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
        new SubscriptionCancelled('user_request', makeActivationPeriodStart()->modify('+1 month')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);

    expect($aggregate->status())->toBe(SubscriptionStatus::Active)
        ->and($aggregate->cancellationReason())->toBe('user_request')
        ->and($aggregate->isCancellationPending())->toBeTrue();
});

it('throws SubscriptionNotCancellable when already cancelled', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        // Trialing: no period to live out, so the cancellation was recorded as biting at once.
        new SubscriptionCancelled('user_request', new DateTimeImmutable('2020-01-01T00:00:00.000+00:00')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelCommand($id));
})->throws(SubscriptionNotCancellable::class);

it('cancel on trialing terminates immediately (no period to wait out)', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        // Trialing: no period to live out, so the cancellation was recorded as biting at once.
        new SubscriptionCancelled('user_request', new DateTimeImmutable('2020-01-01T00:00:00.000+00:00')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);

    expect($aggregate->status())->toBe(SubscriptionStatus::Cancelled)
        ->and($aggregate->cancellationReason())->toBe('user_request')
        ->and($aggregate->isCancellationPending())->toBeFalse();
});

it('refuses activate after cancel on trialing', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        // Trialing: no period to live out, so the cancellation was recorded as biting at once.
        new SubscriptionCancelled('user_request', new DateTimeImmutable('2020-01-01T00:00:00.000+00:00')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id), makeSubscriptionCapturePort());
})->throws(SubscriptionNotActivatable::class);

// ──────────────────────────────────────────────
//  Revert
// ──────────────────────────────────────────────

it('records SubscriptionCancellationReverted while period still active', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
        new SubscriptionCancelled('user_request', makeActivationPeriodStart()->modify('+1 month')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->revertCancellation();
    $this->persistAggregateRoot($aggregate);

    then(new SubscriptionCancellationReverted);
});

it('throws SubscriptionNotCancellable when reverting without pending cancellation', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->revertCancellation();
})->throws(SubscriptionNotCancellable::class, 'not scheduled');

// ──────────────────────────────────────────────
//  Event Serialization
// ──────────────────────────────────────────────

it('SubscriptionCreated survives serialization roundtrip', function () {
    $plan = makeSubscriptionPlan();
    $event = new SubscriptionCreated($plan, makeSubscriptionPaymentMethodId(), 'https://cb.test', ['key' => 'val']);
    $restored = SubscriptionCreated::fromPayload($event->toPayload());

    expect($restored->plan->amount->getAmount())->toBe('2999')
        ->and($restored->plan->interval->every)->toBe(1)
        ->and($restored->plan->interval->period)->toBe(BillingPeriod::Month)
        ->and($restored->paymentMethodId->toString())->toBe(makeSubscriptionPaymentMethodId()->toString())
        ->and($restored->callbackUrl)->toBe('https://cb.test')
        ->and($restored->metadata)->toBe(['key' => 'val']);

    then();
});

it('SubscriptionCreated with trial period survives serialization', function () {
    $plan = new SubscriptionPlan(
        makeSubscriptionAmount(),
        new BillingInterval(1, BillingPeriod::Month),
        new DateInterval('P7D'),
    );
    $event = new SubscriptionCreated($plan, makeSubscriptionPaymentMethodId(), null);
    $restored = SubscriptionCreated::fromPayload($event->toPayload());

    expect($restored->plan->trialPeriod)->not->toBeNull();

    then();
});

it('SubscriptionActivated survives serialization roundtrip', function () {
    $event = new SubscriptionActivated(
        makeSubscriptionPaymentIntentId(),
        new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        new DateTimeImmutable('2026-05-01T00:00:00+00:00'),
    );
    $restored = SubscriptionActivated::fromPayload($event->toPayload());

    expect($restored->paymentIntentId->toString())->toBe('00000000-0000-0000-0000-000000000099')
        ->and($restored->periodStart->format('Y-m-d'))->toBe('2026-04-01')
        ->and($restored->periodEnd->format('Y-m-d'))->toBe('2026-05-01');

    then();
});

it('SubscriptionRenewed survives serialization roundtrip', function () {
    $event = new SubscriptionRenewed(
        new DateTimeImmutable('2026-05-01T00:00:00+00:00'),
        new DateTimeImmutable('2026-06-01T00:00:00+00:00'),
    );
    $restored = SubscriptionRenewed::fromPayload($event->toPayload());

    expect($restored->periodStart->format('Y-m-d'))->toBe('2026-05-01')
        ->and($restored->periodEnd->format('Y-m-d'))->toBe('2026-06-01');

    then();
});

it('SubscriptionCancelled survives serialization roundtrip', function () {
    $event = new SubscriptionCancelled('user_request', new DateTimeImmutable('2020-01-01T00:00:00.000+00:00'));
    $restored = SubscriptionCancelled::fromPayload($event->toPayload());

    expect($restored->reason)->toBe('user_request');

    then();
});


// ──────────────────────────────────────────────
//  Snapshot roundtrip
// ──────────────────────────────────────────────

it('snapshot state roundtrip restores trialing subscription', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    expect($snapshotState['status'])->toBe('trialing')
        ->and($snapshotState['amount'])->toBe('2999')
        ->and($snapshotState['currency'])->toBe('USD')
        ->and($snapshotState['interval_every'])->toBe(1)
        ->and($snapshotState['interval_period'])->toBe('month')
        ->and($snapshotState['payment_method_id'])->toBe(makeSubscriptionPaymentMethodId()->toString())
        ->and($snapshotState['callback_url'])->toBe('https://example.com/webhook')
        ->and($snapshotState['metadata'])->toBe(['tier' => 'pro'])
        ->and($snapshotState['cancellation_reason'])->toBeNull();

    $reconstitute = new ReflectionMethod(SubscriptionAggregate::class, 'reconstituteFromSnapshotState')
        ->invoke(null, $id, $snapshotState);

    $reconstitutedState = (fn () => $this->createSnapshotState())->call($reconstitute);

    expect($reconstitutedState)->toBe($snapshotState);
});

it('snapshot state roundtrip restores active subscription', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    expect($snapshotState['status'])->toBe('active')
        ->and($snapshotState['current_period_start'])->not->toBeNull();

    $reconstitute = new ReflectionMethod(SubscriptionAggregate::class, 'reconstituteFromSnapshotState')
        ->invoke(null, $id, $snapshotState);

    $reconstitutedState = (fn () => $this->createSnapshotState())->call($reconstitute);

    expect($reconstitutedState['status'])->toBe('active');
});

it('snapshot state roundtrip restores cancelled subscription', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        // Trialing: no period to live out, so the cancellation was recorded as biting at once.
        new SubscriptionCancelled('user_request', new DateTimeImmutable('2020-01-01T00:00:00.000+00:00')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    // The snapshot carries when the cancellation bites, not a status rewritten to match it.
    // `status()` reads the two together, so a snapshot restores the same answer without
    // freezing a verdict that depends on the clock.
    expect($snapshotState['cancellation_reason'])->toBe('user_request')
        ->and($snapshotState['cancellation_effective_at'])->not->toBeNull()
        ->and($aggregate->status())->toBe(SubscriptionStatus::Cancelled);
});

it('snapshot state roundtrip preserves pending cancellation', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
        new SubscriptionCancelled('user_request', makeActivationPeriodStart()->modify('+1 month')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    // storedStatus stays Active — cancellation_reason flags the pending cancel.
    expect($snapshotState['status'])->toBe('active')
        ->and($snapshotState['cancellation_reason'])->toBe('user_request');
});


// ──────────────────────────────────────────────
//  when a cancellation bites
// ──────────────────────────────────────────────

/**
 * A signup activated on a payment that was then refused at capture owes nobody a period.
 * The aggregate cannot know that — activation looks the same either way — so the caller
 * says so, and the cancellation takes effect at once rather than at the end of a month
 * nobody paid for.
 */
it('cancels on the spot when the caller says the period was never paid for', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated(), new SubscriptionActivated(
        PaymentIntentId::generate(),
        new DateTimeImmutable('-2 days'),
        new DateTimeImmutable('+28 days'),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelCommand($id, 'capture_failed', CancellationTiming::Immediately));

    // Not the end of the period it was activated for — that month was never paid for.
    expect($aggregate->status())->toBe(SubscriptionStatus::Cancelled)
        ->and($aggregate->cancellationEffectiveAt())->toBeLessThan(new DateTimeImmutable('+1 second'));

    $this->persistAggregateRoot($aggregate);
    then(new SubscriptionCancelled('capture_failed', $aggregate->cancellationEffectiveAt()));
});

/**
 * And the event has to say so, because everything downstream reads the stream rather than
 * the aggregate. Without the instant on the event a projection has to re-derive the rule
 * from the period columns — a second copy that has already drifted from this one.
 */
it('carries the moment it takes effect through serialization', function () {
    $at = new DateTimeImmutable('2026-09-01T12:00:00.000000+00:00');

    $restored = SubscriptionCancelled::fromPayload(new SubscriptionCancelled('user_request', $at)->toPayload());

    expect($restored->reason)->toBe('user_request')
        ->and($restored->effectiveAt->format(DateTimeInterface::RFC3339_EXTENDED))
        ->toBe($at->format(DateTimeInterface::RFC3339_EXTENDED));
});

/**
 * A cancellation asked for at the end of the period lands on the end of the period, so a
 * subscriber keeps what they paid for.
 */
it('lets a paid-for period run out when that is what was asked', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();
    $periodStart = new DateTimeImmutable('-2 days');

    given(makeSubscriptionCreated(), new SubscriptionActivated(
        PaymentIntentId::generate(),
        $periodStart,
        $periodStart->modify('+1 month'),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelCommand($id, 'user_request'));

    expect($aggregate->status())->toBe(SubscriptionStatus::Active)
        ->and($aggregate->isCancellationPending())->toBeTrue();
});
