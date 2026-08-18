<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\AggregateRoot;
use EventSauce\EventSourcing\AggregateRootBehaviour;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Snapshotting\AggregateRootWithSnapshotting;
use EventSauce\EventSourcing\Snapshotting\SnapshottingBehaviour;
use Money\Currency;
use Money\Money;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Domain\Subscription\Command\ActivateSubscriptionCommand;
use Techork\PaymentService\Domain\Subscription\Command\CancelSubscriptionCommand;
use Techork\PaymentService\Domain\Subscription\Command\CreateSubscriptionCommand;
use Techork\PaymentService\Domain\Subscription\Command\RecordSubscriptionRenewalCommand;
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
use Techork\PaymentService\Domain\Subscription\ValueObject\BillingInterval;
use Techork\PaymentService\Domain\Subscription\ValueObject\CancellationTiming;
use Techork\PaymentService\Domain\Subscription\ValueObject\BillingPeriod;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;

/**
 * The aggregate-root id type is bound on the `@use` below rather than here: EventSauce's
 * AggregateRootWithSnapshotting extends the generic AggregateRoot without carrying its
 * template forward, so `@implements AggregateRoot<SubscriptionId>` names an interface this class
 * does not implement directly and psalm rejects it.
 */
final class SubscriptionAggregate implements AggregateRootWithSnapshotting
{
    /** @use AggregateRootBehaviour<SubscriptionId> */
    use AggregateRootBehaviour;
    use SnapshottingBehaviour;

    /**
     * Stored status normally holds Trialing or Active, and Cancelled is a
     * computed result — it materialises once the current period ends
     * after a SubscriptionCancelled event. The exception is a subscription
     * cancelled before it was ever activated: with no period end to compute
     * against, Cancelled goes straight into the stored status.
     * See {@see self::status()} and {@see self::applySubscriptionCancelled()}.
     */
    private SubscriptionStatus $storedStatus = SubscriptionStatus::Trialing;

    private Money $amount;

    private BillingInterval $interval;

    private ?DateInterval $trialPeriod = null;

    private PaymentMethodId $paymentMethodId;

    private ?DateTimeImmutable $currentPeriodStart = null;

    private ?string $callbackUrl = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    /** Non-null after a SubscriptionCancelled event; cleared on revert. */
    private ?string $cancellationReason = null;

    private ?DateTimeImmutable $cancellationEffectiveAt = null;

    /**
     * The most recently submitted payment intent — initial payment today,
     * future renewal payments will overwrite this. Stored as a single scalar
     * to avoid unbounded growth across the subscription lifetime.
     */
    private ?PaymentIntentId $lastPaymentIntentId = null;

    #[Override]
    public function aggregateRootId(): SubscriptionId
    {
        return SubscriptionId::fromString($this->aggregateRootId->toString());
    }

    /**
     * Cancellation is recorded as an event but, while a billing period is under
     * way, only takes effect at the end of it. Until then the subscription keeps
     * for the customer the status it already had, Trialing or Active. One
     * cancelled before activation has no period to wait out and is Cancelled
     * immediately.
     */
    public function status(): SubscriptionStatus
    {
        // Against the instant the cancellation recorded, not against a rule rebuilt here
        // from the period. The event decided it once, and this is the same comparison every
        // reader downstream has to make — see {@see SubscriptionCancelled::$effectiveAt}.
        if ($this->cancellationEffectiveAt !== null && $this->cancellationEffectiveAt <= new DateTimeImmutable()) {
            return SubscriptionStatus::Cancelled;
        }

        return $this->storedStatus;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function currentPeriodEnd(): ?DateTimeImmutable
    {
        if ($this->currentPeriodStart === null) {
            return null;
        }

        return $this->interval->periodEndFrom($this->currentPeriodStart);
    }

    public function cancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    /**
     * When the cancellation bites, or null when none was asked for.
     *
     * Exposed because `status()` is not a fact a reader can store: it is a comparison
     * against the clock, and a projection that copies its answer at write time says
     * `active` for ever. Store this instant and compare it — the same thing this aggregate
     * does, from the same number, rather than a second copy of the rule derived from the
     * period columns.
     */
    public function cancellationEffectiveAt(): ?DateTimeImmutable
    {
        return $this->cancellationEffectiveAt;
    }

    public function isCancellationPending(): bool
    {
        return $this->cancellationReason !== null && $this->status() !== SubscriptionStatus::Cancelled;
    }

    public function lastPaymentIntentId(): ?PaymentIntentId
    {
        return $this->lastPaymentIntentId;
    }

    public static function create(CreateSubscriptionCommand $command): self
    {
        $self = new self($command->subscriptionId());
        $self->recordThat(new SubscriptionCreated(
            $command->plan(),
            $command->paymentMethodId(),
            $command->callbackUrl(),
            $command->metadata(),
        ));

        return $self;
    }

    /**
     * Takes an **authorized** payment intent, runs the checks activation is
     * responsible for, and only then moves the money by capturing it.
     *
     * Same shape as {@see \Techork\PaymentService\Domain\Checkout\CheckoutAggregate::pay},
     * and for the same reason: capture is the step that happens once, so "one
     * payment intent activates at most one subscription" follows from it instead
     * of needing a rule of its own. The first activation leaves the intent
     * `Charged`, so a second one on the same intent fails the `Authorized` check
     * above — before the acquirer is touched.
     *
     * Sequential only. Two concurrent activations both read `Authorized` from
     * their own hydration and can both capture; see {@see SubscriptionCapturePort}
     * for why the domain cannot close that and where it has to be closed.
     *
     * @param  SubscriptionCapturePort  $capturePort  The subscription's own port,
     *   reached only once every check has passed.
     */
    public function activate(ActivateSubscriptionCommand $command, SubscriptionCapturePort $capturePort): void
    {
        $this->status() === SubscriptionStatus::Trialing
            || throw SubscriptionNotActivatable::withStatus($this->status());

        $paymentIntent = $command->paymentIntent();
        $paymentIntent->status() === PaymentIntentStatus::Authorized
            || throw SubscriptionNotActivatable::paymentIntentNotAuthorized($paymentIntent->status());
        $paymentIntent->amount()->equals($this->amount)
            || throw SubscriptionNotActivatable::amountMismatch();

        // Returns or throws, nothing in between: capture has no business failure
        // mode, so there is no declined branch to record. A failure propagates and
        // nothing is recorded, so the subscription stays in the status it already
        // had and a retry is simply the same call again.
        $capturePort->capture(new SubscriptionCaptureRequest(
            subscriptionId: $this->aggregateRootId(),
            paymentIntentId: $paymentIntent->aggregateRootId(),
            amount: $this->amount,
        ));

        $periodStart = $command->periodStart();

        $this->recordThat(new SubscriptionActivated(
            $paymentIntent->aggregateRootId(),
            $periodStart,
            $this->interval->periodEndFrom($periodStart),
        ));
    }

    public function renew(RecordSubscriptionRenewalCommand $command): void
    {
        $this->status()->isRenewable()
            || throw SubscriptionNotRenewable::withStatus($this->status());
        $this->cancellationReason === null
            || throw SubscriptionNotRenewable::pendingCancellation();

        $periodStart = $command->periodStart();

        $this->recordThat(new SubscriptionRenewed(
            $periodStart,
            $this->interval->periodEndFrom($periodStart),
        ));
    }

    public function cancel(CancelSubscriptionCommand $command): void
    {
        $this->status()->isCancellable()
            || throw SubscriptionNotCancellable::withStatus($this->status());
        $this->cancellationReason === null
            || throw SubscriptionNotCancellable::alreadyPending();

        $this->recordThat(new SubscriptionCancelled($command->reason(), $this->cancellationTakesEffectAt($command->timing())));
    }

    /**
     * The end of the period when there is one to live out, and now when there is not.
     *
     * `Immediately` overrides a period that exists, which is the case the aggregate cannot
     * reach on its own: a signup activated on a payment that was never captured is owed
     * nothing, and running it to the end of a month nobody paid for is the failure the
     * timing exists to prevent.
     */
    private function cancellationTakesEffectAt(CancellationTiming $timing): DateTimeImmutable
    {
        $periodEnd = $this->currentPeriodEnd();

        return $timing === CancellationTiming::Immediately || $periodEnd === null
            ? self::nowAtStreamPrecision()
            : $periodEnd;
    }

    /**
     * The clock, rounded to what the event stream can carry.
     *
     * Payloads serialise instants as RFC3339_EXTENDED, which keeps milliseconds. Minting a
     * raw `new DateTimeImmutable()` puts microseconds on the aggregate that no reader can
     * ever see, so the value in memory stops equalling the value everyone else gets — and
     * a serialisation round-trip of the event stops being an identity.
     */
    private static function nowAtStreamPrecision(): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        return DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $now->format(DateTimeInterface::RFC3339_EXTENDED))
            ?: $now;
    }

    public function revertCancellation(): void
    {
        $this->isCancellationPending() || throw SubscriptionNotCancellable::notScheduled();

        $this->recordThat(new SubscriptionCancellationReverted);
    }

    #[Override]
    protected function createSnapshotState(): array
    {
        return [
            'status' => $this->storedStatus->value,
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'interval_every' => $this->interval->every,
            'interval_period' => $this->interval->period->value,
            'trial_period' => $this->trialPeriod?->format('P%yY%mM%dDT%hH%iM%sS'),
            'payment_method_id' => $this->paymentMethodId->toString(),
            'current_period_start' => $this->currentPeriodStart?->format(DateTimeInterface::RFC3339_EXTENDED),
            'callback_url' => $this->callbackUrl,
            'metadata' => $this->metadata,
            'cancellation_reason' => $this->cancellationReason,
            'cancellation_effective_at' => $this->cancellationEffectiveAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'last_payment_intent_id' => $this->lastPaymentIntentId?->toString(),
        ];
    }

    #[Override]
    protected static function reconstituteFromSnapshotState(AggregateRootId $id, $state): AggregateRootWithSnapshotting
    {
        // EventSauce's signature is the widest id type; a snapshot of this aggregate can
        // only carry its own.
        assert($id instanceof SubscriptionId);

        $self = new self($id);
        $self->storedStatus = SubscriptionStatus::from($state['status']);
        $self->amount = new Money($state['amount'], new Currency($state['currency']));
        $self->interval = new BillingInterval($state['interval_every'], BillingPeriod::from($state['interval_period']));
        $self->trialPeriod = isset($state['trial_period']) ? new DateInterval($state['trial_period']) : null;
        $self->paymentMethodId = PaymentMethodId::fromString($state['payment_method_id']);
        // Refused for the same reason, and it matters more here: the current period start is
        // what every renewal date is computed from, so losing it to a silent null would
        // rebill the subscriber against a period the system invented.
        $self->currentPeriodStart = isset($state['current_period_start'])
            ? (DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $state['current_period_start'])
                ?: throw new RuntimeException("Subscription snapshot '{$id->toString()}' carries an unreadable current_period_start: '{$state['current_period_start']}'."))
            : null;
        $self->callbackUrl = $state['callback_url'];
        $self->metadata = $state['metadata'] ?? [];
        $self->cancellationReason = $state['cancellation_reason'] ?? null;
        // Refused rather than nulled, for the same reason as the period start above: a
        // subscription whose cancellation moment cannot be read would come back looking
        // like one nobody cancelled.
        $self->cancellationEffectiveAt = isset($state['cancellation_effective_at'])
            ? (DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $state['cancellation_effective_at'])
                ?: throw new RuntimeException("Subscription snapshot '{$id->toString()}' carries an unreadable cancellation_effective_at: '{$state['cancellation_effective_at']}'."))
            : null;
        $self->lastPaymentIntentId = isset($state['last_payment_intent_id']) ? PaymentIntentId::fromString($state['last_payment_intent_id']) : null;

        return $self;
    }

    protected function applySubscriptionCreated(SubscriptionCreated $event): void
    {
        $this->storedStatus = SubscriptionStatus::Trialing;
        $this->amount = $event->plan->amount;
        $this->interval = $event->plan->interval;
        $this->trialPeriod = $event->plan->trialPeriod;
        $this->paymentMethodId = $event->paymentMethodId;
        $this->callbackUrl = $event->callbackUrl;
        $this->metadata = $event->metadata;
    }

    protected function applySubscriptionActivated(SubscriptionActivated $event): void
    {
        $this->storedStatus = SubscriptionStatus::Active;
        $this->currentPeriodStart = $event->periodStart;
        $this->lastPaymentIntentId = $event->paymentIntentId;
    }

    protected function applySubscriptionRenewed(SubscriptionRenewed $event): void
    {
        $this->storedStatus = SubscriptionStatus::Active;
        $this->currentPeriodStart = $event->periodStart;
    }

    /**
     * Reads the event and nothing else. It used to reconstruct part of the decision here —
     * immediate when there was no period — and leave the rest to `status()`. Applying a
     * rule that consults the clock is also what made a replay's outcome depend on when the
     * replay ran; the instant is now fixed at the moment of cancellation and never moves.
     */
    protected function applySubscriptionCancelled(SubscriptionCancelled $event): void
    {
        $this->cancellationReason = $event->reason;
        $this->cancellationEffectiveAt = $event->effectiveAt;
    }

    protected function applySubscriptionCancellationReverted(): void
    {
        $this->cancellationReason = null;
        $this->cancellationEffectiveAt = null;
    }
}
