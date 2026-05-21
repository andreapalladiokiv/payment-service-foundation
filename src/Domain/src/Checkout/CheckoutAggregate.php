<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout;

use Techork\PaymentService\Domain\Checkout\Command\CreateCheckoutCommand;
use Techork\PaymentService\Domain\Checkout\Command\PayCheckoutCommand;
use Techork\PaymentService\Domain\Checkout\Command\RecordCheckoutChargeFailureCommand;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCancelled;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutCreated;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutFailed;
use Techork\PaymentService\Domain\Checkout\Event\CheckoutPaymentSubmitted;
use Techork\PaymentService\Domain\Checkout\Exception\CheckoutCannotBeCancelled;
use Techork\PaymentService\Domain\Checkout\Exception\CheckoutNotPayable;
use Techork\PaymentService\Domain\Checkout\Exception\InvalidCheckoutPlan;
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

    public function pay(PayCheckoutCommand $command): void
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

        $paymentIntent->status() === PaymentIntentStatus::Charged || throw CheckoutNotPayable::paymentIntentNotAuthorized($paymentIntent->status());
        $paymentIntent->amount()->equals($this->amount) || throw CheckoutNotPayable::amountMismatch();

        $this->recordThat(new CheckoutPaymentSubmitted(
            paymentIntentId: $paymentIntent->aggregateRootId(),
            subscriptionId: $subscription?->aggregateRootId(),
        ));
    }

    public function recordChargeFailure(RecordCheckoutChargeFailureCommand $command): void
    {
        $this->recordThat(new CheckoutFailed($command->reason()));
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

    protected function applyCheckoutFailed(CheckoutFailed $event): void
    {
        $this->status = CheckoutStatus::Failed;
    }

    protected function applyCheckoutCancelled(CheckoutCancelled $event): void
    {
        $this->status = CheckoutStatus::Cancelled;
    }
}
