<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\AggregateRootWithAggregates;
use EventSauce\EventSourcing\Snapshotting\AggregateRootWithSnapshotting;
use EventSauce\EventSourcing\Snapshotting\SnapshottingBehaviour;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;
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
use Techork\PaymentService\Domain\PaymentIntent\Refund\Exception\InvalidRefund;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Exception\RefundNotFound;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\Request\RefundRequest;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Port\RefundPort;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Refund;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * @implements AggregateRootWithSnapshotting<PaymentIntentId>
 */
final class PaymentIntentAggregate implements AggregateRootWithSnapshotting
{
    /** @use AggregateRootWithAggregates<PaymentIntentId, Refund> */
    use AggregateRootWithAggregates;
    use SnapshottingBehaviour;

    private PaymentIntentStatus $status;

    private Money $amount;

    /**
     * Funds actually captured from the cardholder so far. Null until a
     * Charged/Captured/Imported(Charged) event lands; refundableAmount()
     * treats null as zero so refund() guards work uniformly.
     */
    private ?Money $captured = null;

    private PaymentInstrument $instrument;

    private CaptureMethod $captureMethod;

    /**
     * Nullable to accommodate hosted-flow imports where no merchant-side
     * billing record exists. Domain operations (create/charge/authorize) always
     * supply one, so post-Imported states still have it.
     */
    private ?BillingAddress $billingAddress = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private ?Challenge $challenge = null;

    private ?ChallengeResult $challengeResult = null;

    /** @var array<string, Refund> indexed by RefundId string */
    private array $refunds = [];

    public function aggregateRootId(): PaymentIntentId
    {
        return PaymentIntentId::fromString($this->aggregateRootId->toString());
    }

    public function status(): PaymentIntentStatus
    {
        return $this->status;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function instrument(): PaymentInstrument
    {
        return $this->instrument;
    }

    public function captureMethod(): CaptureMethod
    {
        return $this->captureMethod;
    }

    public function billingAddress(): ?BillingAddress
    {
        return $this->billingAddress;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function challenge(): ?Challenge
    {
        return $this->challenge;
    }

    public function challengeResult(): ?ChallengeResult
    {
        return $this->challengeResult;
    }

    /**
     * Remaining amount available for refund. Computed: amount minus the
     * sum of every refund that has settled successfully.
     */
    public function refundableAmount(): Money
    {
        $captured = $this->captured ?? new Money(0, $this->amount->getCurrency());

        return $captured->subtract(...array_values(array_map(
            static fn (Refund $refund): Money => $refund->amount(),
            array_filter($this->refunds, static fn (Refund $refund): bool => $refund->isProcessed()),
        )));
    }

    /** @return array<string, Refund> */
    public function refunds(): array
    {
        return $this->refunds;
    }

    public static function create(CreatePaymentIntentCommand $command, CreatePort $port): self
    {
        $command->amount()->isPositive() || throw InvalidPaymentIntent::nonPositiveAmount();
        $command->instrument()->isValid() || throw InvalidPaymentIntent::unusablePaymentSource();

        $self = new self($command->paymentIntentId());

        try {
            $challenge = $port->create(new CreateRequest(
                paymentIntentId: $command->paymentIntentId(),
                amount: $command->amount(),
                instrument: $command->instrument(),
                captureMethod: $command->captureMethod(),
                billingAddress: $command->billingAddress(),
                challengeResult: $command->challengeResult(),
            ));
        } catch (GatewayDeclinedException $e) {
            $self->recordThat(new PaymentIntentFailed(
                $command->amount(),
                $command->instrument(),
                $command->captureMethod(),
                $command->billingAddress(),
                $command->metadata(),
                $e->reason,
                $command->challengeResult(),
            ));

            return $self;
        }

        // Step-up required — branch off and wait for confirmChallenge().
        if ($challenge !== null) {
            $self->recordThat(new PaymentIntentRequiresAction(
                $command->amount(),
                $command->instrument(),
                $command->captureMethod(),
                $command->billingAddress(),
                $command->metadata(),
                $challenge,
            ));

            return $self;
        }

        $self->chargeOrAuthorize(
            $command->captureMethod(),
            $command->amount(),
            $command->instrument(),
            $command->billingAddress(),
            $command->metadata(),
            $command->challengeResult(),
        );

        return $self;
    }

    public function capture(CapturePaymentIntentCommand $command, CapturePort $port): void
    {
        $this->status === PaymentIntentStatus::Authorized || throw PaymentIntentCannotBeCaptured::withStatus($this->status);
        $this->captureMethod !== CaptureMethod::Immediate || throw PaymentIntentCannotBeCaptured::immediate();

        try {
            $port->capture(new CaptureRequest(
                paymentIntentId: $this->aggregateRootId(),
                amount: $command->amount(),
            ));
        } catch (GatewayDeclinedException $e) {
            $this->recordThat($this->failedFromState($e->reason));

            return;
        }

        $this->recordThat(new PaymentIntentCaptured($command->amount()));
    }

    public function cancel(CancelPaymentIntentCommand $command, CancelPort $port): void
    {
        static $cancelable = [PaymentIntentStatus::Authorized, PaymentIntentStatus::RequiresAction];
        in_array($this->status, $cancelable, true) || throw PaymentIntentCannotBeCancelled::withStatus($this->status);

        try {
            $port->cancel(new CancelRequest($this->aggregateRootId()));
        } catch (GatewayDeclinedException $e) {
            $this->recordThat($this->failedFromState($e->reason));

            return;
        }

        $this->recordThat(new PaymentIntentCancelled($command->reason()));
    }

    public function confirmChallenge(ChallengeResult $result): void
    {
        $this->status === PaymentIntentStatus::RequiresAction || throw PaymentIntentChallengeNotPending::withStatus($this->status);

        $failureReason = $result->accept(new ChallengeFailureReasonExtractor);

        if ($failureReason !== null) {
            $this->recordThat($this->failedFromState($failureReason, $result));

            return;
        }

        // Challenge succeeded — rejoin the original pay() flow with the
        // result attached, as if the gateway had settled inline.
        $this->chargeOrAuthorize(
            $this->captureMethod,
            $this->amount,
            $this->instrument,
            $this->billingAddress,
            $this->metadata,
            $result,
        );
    }

    public function refund(CreateRefundCommand $command, RefundPort $port): void
    {
        $this->status === PaymentIntentStatus::Charged || throw PaymentIntentCannotBeRefunded::withStatus($this->status);
        $command->amount()->isPositive() || throw InvalidRefund::nonPositiveAmount();
        $command->amount()->getCurrency()->equals($this->amount->getCurrency())
            || throw InvalidRefund::currencyMismatch($this->amount->getCurrency(), $command->amount()->getCurrency());

        $remaining = $this->refundableAmount();
        $command->amount()->greaterThan($remaining) && throw PaymentIntentRefundExceedsAmount::create($remaining, $command->amount());

        try {
            $port->refund(new RefundRequest(
                paymentIntentId: $this->aggregateRootId(),
                refundId: $command->refundId(),
                amount: $command->amount(),
                retryInstrument: $command->retryInstrument(),
            ));
        } catch (GatewayDeclinedException $e) {
            $this->recordThat(new RefundFailed($command->refundId(), $command->amount(), $e->reason, $command->retryInstrument()));

            return;
        }

        $this->recordThat(new RefundProcessed($command->refundId(), $command->amount(), $command->retryInstrument()));
    }

    public function recordRefundFee(RecordRefundFeeCommand $command): void
    {
        array_key_exists($command->refundId()->toString(), $this->refunds) || throw RefundNotFound::withId($command->refundId());

        $this->recordThat(new RefundFeeRecorded(
            $command->refundId(),
            $command->fee(),
            $command->observedAt(),
        ));
    }

    /**
     * Records the processor / acquirer fee paid for this PaymentIntent. The
     * signal arrives out-of-band (webhook / settlement import) so we don't
     * gate on aggregate status — receiving fee data for a Cancelled or
     * Failed PI is benign noise we'd rather see in the log than reject.
     */
    public function recordFee(RecordPaymentIntentFeeCommand $command): void
    {
        $this->recordThat(new PaymentIntentFeeRecorded($command->fee(), $command->observedAt()));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function chargeOrAuthorize(
        CaptureMethod $captureMethod,
        Money $amount,
        PaymentInstrument $instrument,
        BillingAddress $billingAddress,
        array $metadata,
        ?ChallengeResult $challengeResult,
    ): void {
        if ($captureMethod === CaptureMethod::Immediate) {
            $this->recordThat(new PaymentIntentCharged($amount, $instrument, $captureMethod, $billingAddress, $metadata, $challengeResult));
        } else {
            $this->recordThat(new PaymentIntentAuthorized($amount, $instrument, $captureMethod, $billingAddress, $metadata, $challengeResult));
        }
    }

    private function failedFromState(string $reason, ?ChallengeResult $challengeResult = null): PaymentIntentFailed
    {
        return new PaymentIntentFailed(
            $this->amount,
            $this->instrument,
            $this->captureMethod,
            $this->billingAddress,
            $this->metadata,
            $reason,
            $challengeResult ?? $this->challengeResult,
        );
    }

    private function bootRefund(RefundId $id): void
    {
        $key = $id->toString();
        if (isset($this->refunds[$key])) {
            return;
        }
        $refund = new Refund($id);
        $this->refunds[$key] = $refund;
        $this->registerAggregate($refund);
    }

    protected function createSnapshotState(): array
    {
        return [
            'status' => $this->status->value,
            'amount' => $this->amount->getAmount(),
            'currency' => $this->amount->getCurrency()->getCode(),
            'captured' => $this->captured?->getAmount(),
            'instrument' => $this->instrument->toPayload(),
            'capture_method' => $this->captureMethod->value,
            'metadata' => $this->metadata,
            'billing_address' => $this->billingAddress?->toArray(),
            'challenge' => $this->challenge === null ? null : ChallengeArraySerializer::toArray($this->challenge),
            'challenge_result' => $this->challengeResult === null ? null : ChallengeResultArraySerializer::toArray($this->challengeResult),
            'refunds' => array_map(fn (Refund $r) => $r->toSnapshot(), array_values($this->refunds)),
        ];
    }

    protected static function reconstituteFromSnapshotState(AggregateRootId $id, $state): AggregateRootWithSnapshotting
    {
        $self = new self($id);
        $self->status = PaymentIntentStatus::from($state['status']);
        $currency = new Currency($state['currency']);
        $self->amount = new Money($state['amount'], $currency);
        $self->captured = isset($state['captured']) ? new Money($state['captured'], $currency) : null;
        $self->instrument = PaymentInstrumentFactory::fromPayload($state['instrument']);
        $self->captureMethod = CaptureMethod::from($state['capture_method']);
        $self->metadata = $state['metadata'] ?? [];
        $self->billingAddress = isset($state['billing_address']) ? BillingAddress::fromArray($state['billing_address']) : null;
        $self->challenge = isset($state['challenge']) ? ChallengeArraySerializer::fromArray($state['challenge']) : null;
        $self->challengeResult = isset($state['challenge_result']) ? ChallengeResultArraySerializer::fromArray($state['challenge_result']) : null;

        foreach ($state['refunds'] ?? [] as $refundState) {
            $refund = Refund::fromSnapshot($refundState);
            $self->refunds[$refund->id()->toString()] = $refund;
            $self->registerAggregate($refund);
        }

        return $self;
    }

    protected function applyPaymentIntentImported(PaymentIntentImported $event): void
    {
        $this->status = $event->status;
        $this->amount = $event->amount;
        $this->instrument = $event->instrument;
        $this->billingAddress = $event->billingAddress;
        $this->captureMethod = $event->captureMethod;
        if ($event->status === PaymentIntentStatus::Charged) {
            $this->captured = $event->amount;
        }
    }

    protected function applyPaymentIntentAuthorized(PaymentIntentAuthorized $event): void
    {
        $this->status = PaymentIntentStatus::Authorized;
        $this->amount = $event->amount;
        $this->instrument = $event->instrument;
        $this->captureMethod = $event->captureMethod;
        $this->billingAddress = $event->billingAddress;
        $this->metadata = $event->metadata;
        $this->challengeResult = $event->challengeResult;
        $this->challenge = null;
    }

    protected function applyPaymentIntentCharged(PaymentIntentCharged $event): void
    {
        $this->status = PaymentIntentStatus::Charged;
        $this->amount = $event->amount;
        $this->instrument = $event->instrument;
        $this->captureMethod = $event->captureMethod;
        $this->billingAddress = $event->billingAddress;
        $this->metadata = $event->metadata;
        $this->challengeResult = $event->challengeResult;
        $this->challenge = null;
        $this->captured = $event->amount;
    }

    protected function applyPaymentIntentRequiresAction(PaymentIntentRequiresAction $event): void
    {
        $this->status = PaymentIntentStatus::RequiresAction;
        $this->amount = $event->amount;
        $this->instrument = $event->instrument;
        $this->captureMethod = $event->captureMethod;
        $this->billingAddress = $event->billingAddress;
        $this->metadata = $event->metadata;
        $this->challenge = $event->challenge;
    }

    protected function applyPaymentIntentFailed(PaymentIntentFailed $event): void
    {
        $this->status = PaymentIntentStatus::Failed;
        $this->amount = $event->amount;
        $this->instrument = $event->instrument;
        $this->captureMethod = $event->captureMethod;
        $this->billingAddress = $event->billingAddress;
        $this->metadata = $event->metadata;
        $this->challengeResult = $event->challengeResult;
        $this->challenge = null;
    }

    protected function applyPaymentIntentCancelled(PaymentIntentCancelled $event): void
    {
        $this->status = PaymentIntentStatus::Cancelled;
        $this->challenge = null;
    }

    protected function applyPaymentIntentCaptured(PaymentIntentCaptured $event): void
    {
        $this->status = PaymentIntentStatus::Charged;
        $this->captured = $event->capturedAmount;
    }

    protected function applyRefundProcessed(RefundProcessed $event): void
    {
        $this->bootRefund($event->refundId);
    }

    protected function applyRefundFailed(RefundFailed $event): void
    {
        $this->bootRefund($event->refundId);
    }

    protected function applyRefundImported(RefundImported $event): void
    {
        $this->bootRefund($event->refundId);
    }

    protected function applyPaymentIntentFeeRecorded(PaymentIntentFeeRecorded $event): void {}
}
