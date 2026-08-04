<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Tests\Support\StubPaymentIntentFirewall;
use Techork\PaymentService\Domain\Checkout\CheckoutAggregate;
use Techork\PaymentService\Domain\Checkout\Command\CreateCheckoutCommand;
use Techork\PaymentService\Domain\Checkout\Command\PayCheckoutCommand;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCancelled;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCreated;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutPaymentSubmitted;
use Techork\PaymentService\Domain\Checkout\Exception\CheckoutCannotBeCancelled;
use Techork\PaymentService\Domain\Checkout\Exception\CheckoutNotPayable;
use Techork\PaymentService\Domain\Checkout\Port\CheckoutCapturePort;
use Techork\PaymentService\Domain\Checkout\Port\Request\CheckoutCaptureRequest;
use Techork\PaymentService\Domain\Checkout\Exception\InvalidCheckoutPlan;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Domain\Subscription\Command\CreateSubscriptionCommand;
use Techork\PaymentService\Domain\Subscription\ValueObject\BillingInterval;
use Techork\PaymentService\Domain\Subscription\ValueObject\BillingPeriod;
use Techork\PaymentService\Domain\Subscription\Event\SubscriptionActivated;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionPlan;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\Command\CapturePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\Command\CreatePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentCannotBeCaptured;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreateOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\CaptureOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Domain\Subscription\SubscriptionAggregate;
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
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Tests\Support\CheckoutAggregateTestCase;
use function EventSauce\EventSourcing\PestTooling\given;
use function EventSauce\EventSourcing\PestTooling\then;
use function EventSauce\EventSourcing\PestTooling\when;

uses(CheckoutAggregateTestCase::class);

// ──────────────────────────────────────────────
//  Helpers
// ──────────────────────────────────────────────

function makeCheckoutAmount(): Money
{
    return new Money(5000, new Currency('USD'));
}

function makeCheckoutPaymentIntentId(): PaymentIntentId
{
    return PaymentIntentId::fromString('00000000-0000-0000-0000-000000000001');
}

function makeCreateCheckoutCommand(CheckoutId $id, ?DateTimeImmutable $expiresAt = null, ?SubscriptionPlan $plan = null): CreateCheckoutCommand
{
    return new readonly class($id, $expiresAt, $plan) implements CreateCheckoutCommand
    {
        public function __construct(private CheckoutId $checkoutId, private ?DateTimeImmutable $expiresAt, private ?SubscriptionPlan $plan) {}

        public function checkoutId(): CheckoutId
        {
            return $this->checkoutId;
        }

        public function amount(): Money
        {
            return makeCheckoutAmount();
        }

        public function description(): ?string
        {
            return 'Test checkout';
        }

        public function callbackUrl(): string
        {
            return 'https://example.com/callback';
        }

        public function expiresAt(): ?DateTimeImmutable
        {
            return $this->expiresAt;
        }

        public function metadata(): array
        {
            return ['order_id' => '123'];
        }

        public function plan(): ?SubscriptionPlan
        {
            return $this->plan;
        }
    };
}

function makeCheckoutPlan(?Money $amount = null): SubscriptionPlan
{
    return new SubscriptionPlan(
        $amount ?? makeCheckoutAmount(),
        new BillingInterval(1, BillingPeriod::Month),
    );
}

function makeChargedSubscription(SubscriptionPlan $plan): SubscriptionAggregate
{
    $id = SubscriptionId::fromString('00000000-0000-0000-0000-000000000002');

    $cmd = new readonly class($id, $plan) implements CreateSubscriptionCommand
    {
        public function __construct(private SubscriptionId $id, private SubscriptionPlan $plan) {}

        public function subscriptionId(): SubscriptionId
        {
            return $this->id;
        }

        public function plan(): SubscriptionPlan
        {
            return $this->plan;
        }

        public function paymentMethodId(): PaymentMethodId
        {
            return PaymentMethodId::fromString('00000000-0000-0000-0000-000000000003');
        }

        public function callbackUrl(): ?string
        {
            return null;
        }

        public function metadata(): array
        {
            return [];
        }
    };

    $sub = SubscriptionAggregate::create($cmd);

    // Bind the subscription to the checkout's payment intent + activate.
    // We can't go through activate() here because it requires loading
    // the actual PI aggregate; apply the event directly instead.
    $start = new DateTimeImmutable('+1 day');
    (fn () => $this->apply(new SubscriptionActivated(
        makeCheckoutPaymentIntentId(),
        $start,
        $start->modify('+1 month'),
    )))->call($sub);

    return $sub;
}

function makeCheckoutPiSuccessPort(): CreatePort
{
    return new readonly class implements CreatePort
    {
        public function create(CreateRequest $request): CreateOutcome { return new CreateOutcome(); }
    };
}

/**
 * An intent the checkout can still act on: authorized, not charged. A
 * capture method other than Immediate is what leaves it that way, and it is
 * also what `PaymentIntentAggregate::capture()` requires.
 */
function makeAuthorizedPiAggregate(): PaymentIntentAggregate
{
    $piId = makeCheckoutPaymentIntentId();

    $cmd = new readonly class($piId) implements CreatePaymentIntentCommand
    {
        public function __construct(private PaymentIntentId $id) {}

        public function paymentIntentId(): PaymentIntentId { return $this->id; }
        public function amount(): Money { return makeCheckoutAmount(); }
        public function instrument(): PaymentInstrument { static $i = null; return $i ??= new Token(TokenId::fromString('01961f5a-0000-7000-8000-000000000001'), new CreditCard(new Number('424242', '4242', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('Test'), new Cvc), ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour'))); }
        public function captureMethod(): CaptureMethod { return CaptureMethod::Automatic; }
        public function billingAddress(): BillingAddress { return new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001'); }
        public function merchantDescriptor(): MerchantDescriptor { return new MerchantDescriptor('CHECKOUT TEST'); }
        public function description(): string { return ''; }
        public function metadata(): array { return []; }
        public function challengeResult(): ?\Techork\PaymentService\Common\Contract\ChallengeResult { return null; }
        public function initiation(): PaymentInitiation { return PaymentInitiation::CardholderInitiated; }
        public function connection(): ?ConnectionContext { return null; }
        public function gatewayId(): ?string { return null; }
    };

    return PaymentIntentAggregate::create($cmd, makeCheckoutPiSuccessPort(), StubPaymentIntentFirewall::allowing());
}

function makePayCheckoutCommand(CheckoutId $id, ?PaymentIntentAggregate $pi = null, ?SubscriptionAggregate $subscription = null): PayCheckoutCommand
{
    $pi ??= makeAuthorizedPiAggregate();

    return new readonly class($id, $pi, $subscription) implements PayCheckoutCommand
    {
        public function __construct(private CheckoutId $checkoutId, private PaymentIntentAggregate $pi, private ?SubscriptionAggregate $subscription) {}
        public function checkoutId(): CheckoutId { return $this->checkoutId; }
        public function paymentIntent(): PaymentIntentAggregate { return $this->pi; }
        public function subscription(): ?SubscriptionAggregate { return $this->subscription; }
    };
}

function makeCheckoutCapturePort(): CheckoutCapturePort
{
    return new readonly class implements CheckoutCapturePort
    {
        public function capture(CheckoutCaptureRequest $request): void {}
    };
}

/**
 * The only way a capture fails: it throws. There is no declined outcome to
 * return, so an adapter with a broken connection surfaces exactly this.
 */
function makeFailingCheckoutCapturePort(string $reason = 'Connection to gateway lost'): CheckoutCapturePort
{
    return new readonly class($reason) implements CheckoutCapturePort
    {
        public function __construct(private string $reason) {}

        public function capture(CheckoutCaptureRequest $request): void
        {
            throw new RuntimeException($this->reason);
        }
    };
}

/**
 * Fails the test if the acquirer is reached at all — pins that every refusal the
 * checkout can decide for itself happens before the capture.
 */
function makeUntouchedCapturePort(): CheckoutCapturePort
{
    return new readonly class implements CheckoutCapturePort
    {
        public function capture(CheckoutCaptureRequest $request): void
        {
            throw new RuntimeException('CheckoutCapturePort must not be reached when a local check already refuses.');
        }
    };
}

/**
 * What a host implementation has to do: capture *through the payment intent
 * aggregate*, so the intent's own Authorized-only invariant is what refuses a
 * second capture. A port that went straight to the gateway would pass these tests
 * while throwing the uniqueness guarantee away, which is why the realistic stub
 * is the one used wherever that guarantee is under test.
 */
function makeIntentCapturingPort(PaymentIntentAggregate $paymentIntent): CheckoutCapturePort
{
    return new readonly class($paymentIntent) implements CheckoutCapturePort
    {
        public function __construct(private PaymentIntentAggregate $paymentIntent) {}

        public function capture(CheckoutCaptureRequest $request): void
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

function makeCheckoutCreated(?DateTimeImmutable $expiresAt = null): CheckoutCreated
{
    return new CheckoutCreated(
        makeCheckoutAmount(),
        'Test checkout',
        'https://example.com/callback',
        $expiresAt,
        ['order_id' => '123'],
    );
}

function makeCheckoutPaymentSubmitted(): CheckoutPaymentSubmitted
{
    return new CheckoutPaymentSubmitted(
        makeCheckoutPaymentIntentId(),
    );
}

// ──────────────────────────────────────────────
//  Create
// ──────────────────────────────────────────────

it('records CheckoutCreated on create', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    when(makeCreateCheckoutCommand($id))
        ->then(makeCheckoutCreated());
});

// ──────────────────────────────────────────────
//  Pay
// ──────────────────────────────────────────────

it('records CheckoutPaymentSubmitted on pay from pending', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(makeCheckoutCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id), makeCheckoutCapturePort());
    $this->persistAggregateRoot($aggregate);

    then(makeCheckoutPaymentSubmitted());
});

it('throws CheckoutNotPayable when paying a non-pending checkout', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(
        makeCheckoutCreated(),
        makeCheckoutPaymentSubmitted(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id), makeCheckoutCapturePort());
})->throws(CheckoutNotPayable::class);

it('throws CheckoutNotPayable when payment intent amount does not match checkout amount', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(makeCheckoutCreated());

    $piId = makeCheckoutPaymentIntentId();
    $mismatchedAmount = new Money(9999, new Currency('USD'));

    $mismatchedPiCmd = new readonly class($piId, $mismatchedAmount) implements CreatePaymentIntentCommand
    {
        public function __construct(private PaymentIntentId $id, private Money $amount) {}
        public function paymentIntentId(): PaymentIntentId { return $this->id; }
        public function amount(): Money { return $this->amount; }
        public function instrument(): PaymentInstrument { static $i = null; return $i ??= new Token(TokenId::fromString('01961f5a-0000-7000-8000-000000000001'), new CreditCard(new Number('424242', '4242', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('Test'), new Cvc), ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour'))); }
        // Authorized, so the amount is what this test trips on and not the state.
        public function captureMethod(): CaptureMethod { return CaptureMethod::Automatic; }
        public function billingAddress(): BillingAddress { return new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001'); }
        public function merchantDescriptor(): MerchantDescriptor { return new MerchantDescriptor('CHECKOUT TEST'); }
        public function description(): string { return ''; }
        public function metadata(): array { return []; }
        public function challengeResult(): ?\Techork\PaymentService\Common\Contract\ChallengeResult { return null; }
        public function initiation(): PaymentInitiation { return PaymentInitiation::CardholderInitiated; }
        public function connection(): ?ConnectionContext { return null; }
        public function gatewayId(): ?string { return null; }
    };

    $pi = PaymentIntentAggregate::create($mismatchedPiCmd, makeCheckoutPiSuccessPort(), StubPaymentIntentFirewall::allowing());

    $cmd = makePayCheckoutCommand($id, $pi);

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay($cmd, makeCheckoutCapturePort());
})->throws(CheckoutNotPayable::class, 'amount does not match');

/**
 * The inverse of what this asserted before the checkout started capturing for
 * itself. An `Immediate` intent has already moved the money at create, before
 * any checkout check ran, so there is nothing left for the checkout to decide —
 * and the same intent could be handed to a second checkout with nothing to
 * refuse it. Now it is the unusable state, and `Authorized` is the required one.
 */
it('throws CheckoutNotPayable when the payment intent was already charged inline', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(makeCheckoutCreated());

    $piId = makeCheckoutPaymentIntentId();
    $piCmd = new readonly class($piId) implements CreatePaymentIntentCommand
    {
        public function __construct(private PaymentIntentId $id) {}
        public function paymentIntentId(): PaymentIntentId { return $this->id; }
        public function amount(): Money { return makeCheckoutAmount(); }
        public function instrument(): PaymentInstrument { static $i = null; return $i ??= new Token(TokenId::fromString('01961f5a-0000-7000-8000-000000000001'), new CreditCard(new Number('424242', '4242', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('Test'), new Cvc), ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour'))); }
        public function captureMethod(): CaptureMethod { return CaptureMethod::Immediate; }
        public function billingAddress(): BillingAddress { return new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001'); }
        public function merchantDescriptor(): MerchantDescriptor { return new MerchantDescriptor('CHECKOUT TEST'); }
        public function description(): string { return ''; }
        public function metadata(): array { return []; }
        public function challengeResult(): ?\Techork\PaymentService\Common\Contract\ChallengeResult { return null; }
        public function initiation(): PaymentInitiation { return PaymentInitiation::CardholderInitiated; }
        public function connection(): ?ConnectionContext { return null; }
        public function gatewayId(): ?string { return null; }
    };

    $pi = PaymentIntentAggregate::create($piCmd, makeCheckoutPiSuccessPort(), StubPaymentIntentFirewall::allowing());
    $cmd = makePayCheckoutCommand($id, $pi);

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay($cmd, makeUntouchedCapturePort());
})->throws(CheckoutNotPayable::class, 'not authorized (status: charged)');

it('throws CheckoutNotPayable when paying an expired checkout', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(makeCheckoutCreated(new DateTimeImmutable('-1 hour')));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id), makeCheckoutCapturePort());
})->throws(CheckoutNotPayable::class, 'expired');

// ──────────────────────────────────────────────
//  Cancel
// ──────────────────────────────────────────────

it('records CheckoutCancelled on cancel from pending', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(makeCheckoutCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel();
    $this->persistAggregateRoot($aggregate);

    then(new CheckoutCancelled);
});

it('throws CheckoutCannotBeCancelled when already charged', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(
        makeCheckoutCreated(),
        makeCheckoutPaymentSubmitted(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel();
})->throws(CheckoutCannotBeCancelled::class);

// ──────────────────────────────────────────────
//  Serialization
// ──────────────────────────────────────────────

it('CheckoutCreated survives serialization roundtrip', function () {
    $expiresAt = new DateTimeImmutable('2026-12-31T23:59:59+00:00');
    $metadata = ['order_id' => 'ord-99', 'items' => [['sku' => 'SKU-1', 'qty' => 2]]];
    $event = new CheckoutCreated(makeCheckoutAmount(), 'A product', 'https://cb.test/hook', $expiresAt, $metadata);
    $payload = $event->toPayload();
    $restored = CheckoutCreated::fromPayload($payload);

    expect($restored->amount->getAmount())->toBe('5000')
        ->and($restored->amount->getCurrency()->getCode())->toBe('USD')
        ->and($restored->description)->toBe('A product')
        ->and($restored->callbackUrl)->toBe('https://cb.test/hook')
        ->and($restored->expiresAt->format(DateTimeInterface::ATOM))->toBe($expiresAt->format(DateTimeInterface::ATOM))
        ->and($restored->metadata)->toBe($metadata);

    then();
});

it('CheckoutCreated defaults metadata to empty array when missing from payload', function () {
    $payload = ['amount' => '5000', 'currency' => 'USD', 'description' => null,
        'callback_url' => 'https://cb.test'];
    $event = CheckoutCreated::fromPayload($payload);

    expect($event->metadata)->toBe([]);

    then();
});

it('CheckoutPaymentSubmitted survives serialization roundtrip', function () {
    $event = new CheckoutPaymentSubmitted(makeCheckoutPaymentIntentId());
    $payload = $event->toPayload();
    $restored = CheckoutPaymentSubmitted::fromPayload($payload);

    expect($restored->paymentIntentId->toString())->toBe('00000000-0000-0000-0000-000000000001');

    then();
});

// ──────────────────────────────────────────────
//  Snapshot roundtrip
// ──────────────────────────────────────────────

it('snapshot state roundtrip restores pending checkout with all fields', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $expiresAt = new DateTimeImmutable('2026-12-31T23:59:59.000+00:00');

    given(makeCheckoutCreated($expiresAt));

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    expect($snapshotState['status'])->toBe('pending')
        ->and($snapshotState['amount'])->toBe('5000')
        ->and($snapshotState['currency'])->toBe('USD')
        ->and($snapshotState['description'])->toBe('Test checkout')
        ->and($snapshotState['callback_url'])->toBe('https://example.com/callback')
        ->and($snapshotState['expires_at'])->not->toBeNull()
        ->and($snapshotState['metadata'])->toBe(['order_id' => '123']);

    $reconstitute = new ReflectionMethod(CheckoutAggregate::class, 'reconstituteFromSnapshotState')->invoke(null, $id, $snapshotState);

    expect($reconstitute->aggregateRootId()->toString())->toBe($id->toString());

    $reconstitutedState = (fn () => $this->createSnapshotState())->call($reconstitute);

    expect($reconstitutedState['status'])->toBe('pending')
        ->and($reconstitutedState['amount'])->toBe('5000')
        ->and($reconstitutedState['currency'])->toBe('USD')
        ->and($reconstitutedState['description'])->toBe('Test checkout')
        ->and($reconstitutedState['callback_url'])->toBe('https://example.com/callback')
        ->and($reconstitutedState['metadata'])->toBe(['order_id' => '123']);
});

it('snapshot state roundtrip restores checkout without expires_at', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(makeCheckoutCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    expect($snapshotState['expires_at'])->toBeNull();

    $reconstitute = new ReflectionMethod(CheckoutAggregate::class, 'reconstituteFromSnapshotState')->invoke(null, $id, $snapshotState);

    $reconstitutedState = (fn () => $this->createSnapshotState())->call($reconstitute);

    expect($reconstitutedState['expires_at'])->toBeNull();
});

it('snapshot state roundtrip restores charged checkout', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(
        makeCheckoutCreated(),
        makeCheckoutPaymentSubmitted(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    expect($snapshotState['status'])->toBe('charged');

    $reconstitute = new ReflectionMethod(CheckoutAggregate::class, 'reconstituteFromSnapshotState')->invoke(null, $id, $snapshotState);

    $reconstitutedState = (fn () => $this->createSnapshotState())->call($reconstitute);
    expect($reconstitutedState['status'])->toBe('charged');
});

it('snapshot state roundtrip restores cancelled checkout', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(
        makeCheckoutCreated(),
        new CheckoutCancelled,
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    expect($snapshotState['status'])->toBe('cancelled');
});

it('snapshot metadata defaults to empty array when missing from state', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    $state = [
        'status' => 'pending',
        'amount' => '5000',
        'currency' => 'USD',
        'description' => null,
        'callback_url' => 'https://example.com',
        'expires_at' => null,
    ];

    $reconstitute = new ReflectionMethod(CheckoutAggregate::class, 'reconstituteFromSnapshotState')->invoke(null, $id, $state);

    $reconstitutedState = (fn () => $this->createSnapshotState())->call($reconstitute);
    expect($reconstitutedState['metadata'])->toBe([]);
});

// ──────────────────────────────────────────────
//  Subscription plan
// ──────────────────────────────────────────────

it('records CheckoutCreated with plan when command carries one', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $plan = makeCheckoutPlan();

    when(makeCreateCheckoutCommand($id, plan: $plan))
        ->then(new CheckoutCreated(
            makeCheckoutAmount(),
            'Test checkout',
            'https://example.com/callback',
            null,
            ['order_id' => '123'],
            $plan,
        ));
});

it('throws InvalidCheckoutPlan when plan amount does not equal checkout amount', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $wrongPlan = makeCheckoutPlan(new Money(9999, new Currency('USD')));

    CheckoutAggregate::create(makeCreateCheckoutCommand($id, plan: $wrongPlan));
})->throws(InvalidCheckoutPlan::class, 'plan amount must equal');

it('throws CheckoutNotPayable when plan is set but subscription is missing', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $plan = makeCheckoutPlan();

    given(new CheckoutCreated(makeCheckoutAmount(), null, null, null, [], $plan));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id), makeCheckoutCapturePort());
})->throws(CheckoutNotPayable::class, 'both be set or both be null');

it('throws CheckoutNotPayable when subscription is provided but plan is missing', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $subscription = makeChargedSubscription(makeCheckoutPlan());

    given(makeCheckoutCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id, subscription: $subscription), makeCheckoutCapturePort());
})->throws(CheckoutNotPayable::class, 'both be set or both be null');

it('records CheckoutPaymentSubmitted with subscription id when both plan and subscription are present', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $plan = makeCheckoutPlan();
    $subscription = makeChargedSubscription($plan);

    given(new CheckoutCreated(makeCheckoutAmount(), null, null, null, [], $plan));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id, subscription: $subscription), makeCheckoutCapturePort());
    $this->persistAggregateRoot($aggregate);

    then(new CheckoutPaymentSubmitted(
        paymentIntentId: makeCheckoutPaymentIntentId(),
        subscriptionId: $subscription->aggregateRootId(),
    ));
});

it('throws CheckoutNotPayable when subscription is cancelled', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $plan = makeCheckoutPlan();
    $subscription = makeChargedSubscription($plan);
    (fn () => $this->apply(
        new \Techork\PaymentService\Domain\Subscription\Event\SubscriptionCancelled('user_request'),
    ))->call($subscription);

    given(new CheckoutCreated(makeCheckoutAmount(), null, null, null, [], $plan));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id, subscription: $subscription), makeCheckoutCapturePort());
})->throws(CheckoutNotPayable::class, 'cancelled subscription');

// ──────────────────────────────────────────────
//  One payment intent pays one checkout
// ──────────────────────────────────────────────

it('captures the payment intent it was handed', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $paymentIntent = makeAuthorizedPiAggregate();

    given(makeCheckoutCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id, $paymentIntent), makeIntentCapturingPort($paymentIntent));
    $this->persistAggregateRoot($aggregate);

    // The money moved as part of paying, not before it.
    expect($paymentIntent->status())->toBe(PaymentIntentStatus::Charged);

    then(new CheckoutPaymentSubmitted(
        paymentIntentId: makeCheckoutPaymentIntentId(),
        subscriptionId: null,
    ));
});

/**
 * The point of taking an authorized intent and capturing it here: capture
 * happens once, so the second checkout has nothing left to take. Note the
 * untouched capture port — the second checkout is refused before the acquirer is
 * reached, so this is not "the gateway rejected a double charge", it is "we never
 * asked for one".
 *
 * This exercises the checkout's own guard, which is what an ordinary sequential
 * second attempt hits. The intent's own refusal, were a caller to get past that
 * guard, propagates from the port as `PaymentIntentCannotBeCaptured` — covered in
 * PaymentIntentAggregateTest: 'throws PaymentIntentCannotBeCaptured on capture
 * from Charged'. It is not a concurrency backstop, and nothing here should be read
 * as claiming one.
 */
it('refuses to pay two checkouts with the same payment intent', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $paymentIntent = makeAuthorizedPiAggregate();

    $firstCheckoutId = CheckoutId::fromString('00000000-0000-0000-0000-0000000000aa');
    $firstCheckout = CheckoutAggregate::create(makeCreateCheckoutCommand($firstCheckoutId));
    $firstCheckout->pay(makePayCheckoutCommand($firstCheckoutId, $paymentIntent), makeIntentCapturingPort($paymentIntent));

    given(makeCheckoutCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id, $paymentIntent), makeUntouchedCapturePort());
})->throws(CheckoutNotPayable::class, 'not authorized (status: charged)');

it('records no event on the second checkout when the intent is already spent', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $paymentIntent = makeAuthorizedPiAggregate();

    $firstCheckoutId = CheckoutId::fromString('00000000-0000-0000-0000-0000000000aa');
    $firstCheckout = CheckoutAggregate::create(makeCreateCheckoutCommand($firstCheckoutId));
    $firstCheckout->pay(makePayCheckoutCommand($firstCheckoutId, $paymentIntent), makeIntentCapturingPort($paymentIntent));

    given(makeCheckoutCreated());

    $aggregate = $this->retrieveAggregateRoot($id);

    try {
        $aggregate->pay(makePayCheckoutCommand($id, $paymentIntent), makeUntouchedCapturePort());
    } catch (CheckoutNotPayable) {
        // asserted by the sibling test; the point here is the absence below
    }

    $this->persistAggregateRoot($aggregate);

    then();
});

/**
 * Capture has no declined branch to record, so a failure propagates untouched and
 * the checkout stays Pending — which is what makes the retry just the same call
 * again. Burning the checkout over what is most likely a lost connection would be
 * the wrong trade, which is why there is no failure event to record.
 */
it('lets a failed capture propagate and records nothing', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $paymentIntent = makeAuthorizedPiAggregate();

    given(makeCheckoutCreated());

    $aggregate = $this->retrieveAggregateRoot($id);

    try {
        $aggregate->pay(
            makePayCheckoutCommand($id, $paymentIntent),
            makeFailingCheckoutCapturePort('Connection to gateway lost'),
        );
        $this->fail('the capture failure should have propagated');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Connection to gateway lost');
    }

    // No public status accessor here, but the empty `then()` is the stronger
    // statement anyway: status only ever moves via an event, and none was
    // recorded, so the checkout is still Pending and payable.
    $this->persistAggregateRoot($aggregate);

    then();
});

it('does not reach the acquirer when a local check already refuses', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();

    given(makeCheckoutCreated(), new CheckoutCancelled);

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id), makeUntouchedCapturePort());
})->throws(CheckoutNotPayable::class, 'cannot be paid in status [cancelled]');

it('throws CheckoutNotPayable when payment intent is not the one bound to the subscription', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $plan = makeCheckoutPlan();

    // Subscription is bound to a different payment intent than the checkout's PI.
    $subscription = SubscriptionAggregate::create(new readonly class implements CreateSubscriptionCommand
    {
        public function subscriptionId(): SubscriptionId { return SubscriptionId::fromString('00000000-0000-0000-0000-000000000002'); }
        public function plan(): SubscriptionPlan { return makeCheckoutPlan(); }
        public function paymentMethodId(): PaymentMethodId { return PaymentMethodId::fromString('00000000-0000-0000-0000-000000000003'); }
        public function callbackUrl(): ?string { return null; }
        public function metadata(): array { return []; }
    });
    $start = new DateTimeImmutable('+1 day');
    (fn () => $this->apply(new SubscriptionActivated(
        PaymentIntentId::fromString('00000000-0000-0000-0000-000000000999'),
        $start,
        $start->modify('+1 month'),
    )))->call($subscription);

    given(new CheckoutCreated(makeCheckoutAmount(), null, null, null, [], $plan));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->pay(makePayCheckoutCommand($id, subscription: $subscription), makeCheckoutCapturePort());
})->throws(CheckoutNotPayable::class, 'not the one bound to the subscription');

it('CheckoutCreated reconstitutes plan from payload', function () {
    $plan = makeCheckoutPlan();
    $event = new CheckoutCreated(makeCheckoutAmount(), null, null, null, [], $plan);
    $restored = CheckoutCreated::fromPayload($event->toPayload());

    expect($restored->plan)->not->toBeNull()
        ->and($restored->plan->amount->getAmount())->toBe('5000')
        ->and($restored->plan->interval->every)->toBe(1)
        ->and($restored->plan->interval->period)->toBe(BillingPeriod::Month);

    then();
});

it('CheckoutCreated defaults plan to null when missing from payload', function () {
    $payload = ['amount' => '5000', 'currency' => 'USD', 'description' => null, 'callback_url' => null];
    $event = CheckoutCreated::fromPayload($payload);

    expect($event->plan)->toBeNull();

    then();
});

it('snapshot state roundtrip carries plan', function () {
    /** @var CheckoutId $id */
    $id = $this->aggregateRootId();
    $plan = makeCheckoutPlan();

    given(new CheckoutCreated(makeCheckoutAmount(), null, null, null, [], $plan));

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    expect($snapshotState['plan'])->toBeArray()
        ->and($snapshotState['plan']['amount'])->toBe('5000')
        ->and($snapshotState['plan']['interval_period'])->toBe('month');

    $reconstitute = new ReflectionMethod(CheckoutAggregate::class, 'reconstituteFromSnapshotState')->invoke(null, $id, $snapshotState);
    $roundtripState = (fn () => $this->createSnapshotState())->call($reconstitute);

    expect($roundtripState['plan'])->toBe($snapshotState['plan']);
});
