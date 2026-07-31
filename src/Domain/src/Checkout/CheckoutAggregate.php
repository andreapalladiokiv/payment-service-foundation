<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout;

use Techork\PaymentService\Domain\Checkout\Command\CreateCheckoutCommand;
use Techork\PaymentService\Domain\Checkout\Command\PayCheckoutCommand;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCancelled;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCreated;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutPaymentSubmitted;
use Techork\PaymentService\Domain\Checkout\Exception\CheckoutCannotBeCancelled;
use Techork\PaymentService\Domain\Checkout\Exception\CheckoutNotPayable;
use Techork\PaymentService\Domain\Checkout\Exception\InvalidCheckoutPlan;
use Techork\PaymentService\Domain\Checkout\Port\CheckoutCapturePort;
use Techork\PaymentService\Domain\Checkout\Port\Request\CheckoutCaptureRequest;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\Subscription\SubscriptionStatus;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionPlan;
use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\AggregateRootBehaviour;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Snapshotting\AggregateRootWithSnapshotting;
use EventSauce\EventSourcing\Snapshotting\SnapshottingBehaviour;
use Money\Currency;
use Money\Money;

/**
 * @implements AggregateRootWithSnapshotting<CheckoutId>
 */
final class CheckoutAggregate implements AggregateRootWithSnapshotting
{
    use AggregateRootBehaviour;
    use SnapshottingBehaviour;

    private CheckoutStatus $status = CheckoutStatus::Pending;

    private Money $amount;

    private ?string $description = null;

    private ?string $callbackUrl = null;

    private ?DateTimeImmutable $expiresAt = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private ?SubscriptionPlan $plan = null;

    public function aggregateRootId(): CheckoutId
    {
        return CheckoutId::fromString($this->aggregateRootId->toString());
    }

    public static function create(CreateCheckoutCommand $command): self
    {
        $plan = $command->plan();
        if ($plan !== null && ! $plan->amount->equals($command->amount())) {
            throw InvalidCheckoutPlan::amountMismatch();
        }

        $self = new self($command->checkoutId());
        $self->recordThat(new CheckoutCreated(
            $command->amount(),
            $command->description(),
            $command->callbackUrl(),
            $command->expiresAt(),
            $command->metadata(),
            $plan,
        ));

        return $self;
    }

    /**
     * Takes an **authorized** payment intent, runs every check the checkout is
     * responsible for, and only then moves the money by capturing it.
     *
     * That ordering is what makes "one payment intent pays at most one checkout"
     * true without any cross-checkout bookkeeping: capture is the step that
     * consumes the intent, so the first checkout leaves it `Charged` and a second
     * one fails the `Authorized` check below. Nothing here has to know that other
     * checkouts exist.
     *
     * SEQUENTIALLY. Two concurrent payments both read `Authorized` from their own
     * hydration of the intent, and the gateway capture happens before either
     * event is appended, so both can take the money — the aggregate guard
     * serializes the bookkeeping, not the charge. The domain cannot close that;
     * see {@see CheckoutCapturePort} for where it has to be closed.
     *
     * @param  CheckoutCapturePort  $capturePort  The checkout's own port, reached
     *   only after every check passes — a checkout that refuses locally never
     *   touches the acquirer. It is also where the two writes this payment
     *   implies (the intent's capture, this checkout's event) are made to commit
     *   together; see the port's contract.
     */
    public function pay(PayCheckoutCommand $command, CheckoutCapturePort $capturePort): void
    {
        $this->status === CheckoutStatus::Pending || throw CheckoutNotPayable::withStatus($this->status);
        $this->expiresAt === null || $this->expiresAt >= new DateTimeImmutable || throw CheckoutNotPayable::expired();

        $paymentIntent = $command->paymentIntent();
        $subscription = $command->subscription();

        ($this->plan === null) === ($subscription === null)
            || throw CheckoutNotPayable::planSubscriptionMismatch();

        $subscription === null || $subscription->cancellationReason() === null
            || throw CheckoutNotPayable::subscriptionCancelled();

        $subscription === null
            || $subscription->lastPaymentIntentId()?->equals($paymentIntent->aggregateRootId())
            || throw CheckoutNotPayable::paymentIntentSubscriptionMismatch();

        // Authorized, not Charged: the checkout is what decides whether the
        // money may be taken, so it must still be takeable when we get here. An
        // intent charged inline at create has already moved the money before any
        // of these checks ran, and a second checkout could be handed the same
        // one with nothing left to refuse.
        $paymentIntent->status() === PaymentIntentStatus::Authorized || throw CheckoutNotPayable::paymentIntentNotAuthorized($paymentIntent->status());
        $paymentIntent->amount()->equals($this->amount) || throw CheckoutNotPayable::amountMismatch();

        // Returns or throws, nothing in between: capture has no business failure
        // mode, so there is no declined branch to record. A failure propagates and
        // nothing is recorded, which leaves the checkout Pending and a retry as
        // simply the same call again.
        $capturePort->capture(new CheckoutCaptureRequest(
            checkoutId: $this->aggregateRootId(),
            paymentIntentId: $paymentIntent->aggregateRootId(),
            amount: $this->amount,
        ));

        $this->recordThat(new CheckoutPaymentSubmitted(
            paymentIntentId: $paymentIntent->aggregateRootId(),
            subscriptionId: $subscription?->aggregateRootId(),
        ));
    }

    public function cancel(): void
    {
        $this->status === CheckoutStatus::Pending || throw CheckoutCannotBeCancelled::withStatus($this->status);

        $this->recordThat(new CheckoutCancelled);
    }

    protected function createSnapshotState(): array
    {
        return [
            'status' => $this->status->value,
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'description' => $this->description,
            'callback_url' => $this->callbackUrl,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'metadata' => $this->metadata,
            'plan' => $this->plan?->toArray(),
        ];
    }

    protected static function reconstituteFromSnapshotState(AggregateRootId $id, $state): AggregateRootWithSnapshotting
    {
        $self = new self($id);
        $self->status = CheckoutStatus::from($state['status']);
        $self->amount = new Money($state['amount'], new Currency($state['currency']));
        $self->description = $state['description'];
        $self->callbackUrl = $state['callback_url'];
        $self->expiresAt = $state['expires_at'] !== null ? DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $state['expires_at']) : null;
        $self->metadata = $state['metadata'] ?? [];
        $self->plan = isset($state['plan']) ? SubscriptionPlan::fromArray($state['plan']) : null;

        return $self;
    }

    protected function applyCheckoutCreated(CheckoutCreated $event): void
    {
        $this->status = CheckoutStatus::Pending;
        $this->amount = $event->amount;
        $this->description = $event->description;
        $this->callbackUrl = $event->callbackUrl;
        $this->expiresAt = $event->expiresAt;
        $this->metadata = $event->metadata;
        $this->plan = $event->plan;
    }

    protected function applyCheckoutPaymentSubmitted(CheckoutPaymentSubmitted $event): void
    {
        $this->status = CheckoutStatus::Charged;
    }

    protected function applyCheckoutCancelled(CheckoutCancelled $event): void
    {
        $this->status = CheckoutStatus::Cancelled;
    }
}
