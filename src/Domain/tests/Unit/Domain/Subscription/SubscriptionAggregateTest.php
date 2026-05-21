<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\PaymentIntent\CaptureMethod;
use Techork\PaymentService\Domain\PaymentIntent\Command\CreatePaymentIntentCommand;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Domain\Subscription\Command\ActivateSubscriptionCommand;
use Techork\PaymentService\Domain\Subscription\Command\CancelSubscriptionCommand;
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

function makeChargedPiForSubscription(?Money $amount = null): PaymentIntentAggregate
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
        public function captureMethod(): CaptureMethod { return CaptureMethod::Immediate; }
        public function billingAddress(): BillingAddress
        {
            return new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001');
        }
        public function metadata(): array { return []; }
        public function challengeResult(): ?\Techork\PaymentService\Common\Contract\ChallengeResult { return null; }
    };

    return PaymentIntentAggregate::create($cmd, makeSubscriptionPiSuccessPort());
}

function makeSubscriptionPiSuccessPort(): CreatePort
{
    return new readonly class implements CreatePort
    {
        public function create(CreateRequest $request): ?Challenge { return null; }
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
    $pi ??= makeChargedPiForSubscription();

    return new readonly class($id, $pi) implements ActivateSubscriptionCommand
    {
        public function __construct(private SubscriptionId $subscriptionId, private PaymentIntentAggregate $pi) {}

        public function subscriptionId(): SubscriptionId { return $this->subscriptionId; }
        public function periodStart(): DateTimeImmutable { return makeActivationPeriodStart(); }
        public function paymentIntent(): PaymentIntentAggregate { return $this->pi; }
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

function makeCancelCommand(SubscriptionId $id, string $reason = 'user_request'): CancelSubscriptionCommand
{
    return new readonly class($id, $reason) implements CancelSubscriptionCommand
    {
        public function __construct(private SubscriptionId $subscriptionId, private string $reason) {}

        public function subscriptionId(): SubscriptionId { return $this->subscriptionId; }
        public function reason(): string { return $this->reason; }
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

it('records SubscriptionActivated on activate from trialing with charged PI', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(makeSubscriptionActivated());
});

it('throws SubscriptionNotActivatable when payment intent is not charged', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    // Build an Authorized (Manual capture) PI, not Charged.
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
        public function captureMethod(): CaptureMethod { return CaptureMethod::Manual; }
        public function billingAddress(): BillingAddress
        {
            return new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001');
        }
        public function metadata(): array { return []; }
        public function challengeResult(): ?\Techork\PaymentService\Common\Contract\ChallengeResult { return null; }
    };
    $pi = PaymentIntentAggregate::create($piCmd, makeSubscriptionPiSuccessPort());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id, $pi));
})->throws(SubscriptionNotActivatable::class, 'requires a charged payment intent');

it('throws SubscriptionNotActivatable when payment intent amount does not match plan', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    $pi = makeChargedPiForSubscription(new Money(9999, new Currency('USD')));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id, $pi));
})->throws(SubscriptionNotActivatable::class, 'does not match subscription plan amount');

it('throws SubscriptionNotActivatable when already active', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id));
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
        new SubscriptionCancelled('user_request'),
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
        new SubscriptionCancelled('user_request'),
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

    then(new SubscriptionCancelled('payment_failed'));
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

    then(new SubscriptionCancelled('user_request'));
});

it('records SubscriptionCancelled on cancel from trialing', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(makeSubscriptionCreated());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(new SubscriptionCancelled('user_request'));
});

it('keeps subscription Active after cancel until the period ends', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
        new SubscriptionCancelled('user_request'),
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
        new SubscriptionCancelled('user_request'),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->cancel(makeCancelCommand($id));
})->throws(SubscriptionNotCancellable::class);

it('cancel on trialing terminates immediately (no period to wait out)', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        new SubscriptionCancelled('user_request'),
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
        new SubscriptionCancelled('user_request'),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->activate(makeActivateCommand($id));
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
        new SubscriptionCancelled('user_request'),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->revertCancellation(makeRevertCancellationCommand($id));
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
    $aggregate->revertCancellation(makeRevertCancellationCommand($id));
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
    $event = new SubscriptionCancelled('user_request');
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
        new SubscriptionCancelled('user_request'),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    // Cancelled before activation: storedStatus jumps to Cancelled because
    // there is no period to wait out.
    expect($snapshotState['status'])->toBe('cancelled')
        ->and($snapshotState['cancellation_reason'])->toBe('user_request');
});

it('snapshot state roundtrip preserves pending cancellation', function () {
    /** @var SubscriptionId $id */
    $id = $this->aggregateRootId();

    given(
        makeSubscriptionCreated(),
        makeSubscriptionActivated(),
        new SubscriptionCancelled('user_request'),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $snapshotState = (fn () => $this->createSnapshotState())->call($aggregate);

    // storedStatus stays Active — cancellation_reason flags the pending cancel.
    expect($snapshotState['status'])->toBe('active')
        ->and($snapshotState['cancellation_reason'])->toBe('user_request');
});

