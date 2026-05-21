<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund;

use EventSauce\EventSourcing\AggregateAppliesKnownEvents;
use EventSauce\EventSourcing\EventSourcedAggregate;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundFailed;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundFeeRecorded;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundImported;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Event\RefundProcessed;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;

/**
 * Child aggregate of {@see \Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate}.
 *
 * Refund has no behaviour-bearing methods of its own — all writes go through
 * the parent's port-driven flow, which records events on the shared stream.
 * This class only filters those events by RefundId and projects state.
 */
final class Refund implements EventSourcedAggregate
{
    use AggregateAppliesKnownEvents;

    private RefundStatus $status;

    private Money $amount;

    private ?Money $fee = null;

    public function __construct(
        private readonly RefundId $id,
    ) {}

    public function id(): RefundId
    {
        return $this->id;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function status(): RefundStatus
    {
        return $this->status;
    }

    public function fee(): ?Money
    {
        return $this->fee;
    }

    public function isProcessed(): bool
    {
        return $this->status === RefundStatus::Processed;
    }

    public function isFailed(): bool
    {
        return $this->status === RefundStatus::Failed;
    }

    protected function applyRefundProcessed(RefundProcessed $event): void
    {
        if (! $event->refundId->equals($this->id)) {
            return;
        }

        $this->amount = $event->amount;
        $this->status = RefundStatus::Processed;
    }

    protected function applyRefundFailed(RefundFailed $event): void
    {
        if (! $event->refundId->equals($this->id)) {
            return;
        }

        $this->amount = $event->amount;
        $this->status = RefundStatus::Failed;
    }

    protected function applyRefundImported(RefundImported $event): void
    {
        if (! $event->refundId->equals($this->id)) {
            return;
        }

        $this->amount = $event->amount;
        $this->status = $event->status;
    }

    protected function applyRefundFeeRecorded(RefundFeeRecorded $event): void
    {
        if (! $event->refundId->equals($this->id)) {
            return;
        }

        $this->fee = $event->fee;
    }

    /** @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id->toString(),
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'status' => $this->status->value,
            'fee_amount' => $this->fee?->getAmount(),
            'fee_currency' => $this->fee?->getCurrency()->getCode(),
        ];
    }

    /** @param array<string, mixed> $state */
    public static function fromSnapshot(array $state): self
    {
        $self = new self(RefundId::fromString($state['id']));
        $self->amount = new Money($state['amount'], new Currency($state['currency']));
        $self->status = RefundStatus::from($state['status']);
        $self->fee = isset($state['fee_amount'])
            ? new Money($state['fee_amount'], new Currency($state['fee_currency']))
            : null;

        return $self;
    }
}
