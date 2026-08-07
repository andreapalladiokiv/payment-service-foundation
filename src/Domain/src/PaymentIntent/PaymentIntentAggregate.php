<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

use EventSauce\EventSourcing\AggregateRoot;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\AggregateRootWithAggregates;
use EventSauce\EventSourcing\Snapshotting\AggregateRootWithSnapshotting;
use EventSauce\EventSourcing\Snapshotting\SnapshottingBehaviour;
use Money\Currency;
use Money\Money;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummaryExtractor;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Domain\PaymentIntent\Command\CancelPaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\Command\CapturePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\Command\CreatePaymentIntentCommand;
use Techork\PaymentService\Domain\PaymentIntent\Command\RecordPaymentIntentFeeCommand;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentAuthorized;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentCancelled;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentCaptured;
use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentCharged;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
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
use Techork\PaymentService\Domain\PaymentIntent\Port\CancelPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\CapturePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengeOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\ConfirmChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\CreatePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Domain\PaymentIntent\Port\GatewayDeclinedException;
use Techork\PaymentService\Domain\PaymentIntent\Port\PaymentIntentFirewallPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CancelRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CaptureRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\ConfirmChallengeRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\CreateRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\VerifyChallengeRequest;
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
use Techork\PaymentService\Domain\PaymentIntent\Refund\Refund;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * The aggregate-root id type is bound on the `@use` below rather than here: EventSauce's
 * AggregateRootWithSnapshotting extends the generic AggregateRoot without carrying its
 * template forward, so `@implements AggregateRoot<PaymentIntentId>` names an interface this class
 * does not implement directly and psalm rejects it.
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
     * Total, because every event that opens an intent carries one — imports included, and
     * `PaymentIntentImported` was the last event where it was ever optional. The property
     * used to be nullable purely so a snapshot written before `billing_address` existed
     * could still reconstitute; that concession made every reader downstream carry a null
     * case that no live aggregate could produce. Absence, where it is genuinely possible,
     * is {@see BillingAddress::unknown()} rather than null.
     */
    private BillingAddress $billingAddress;

    /** @var array<string, mixed> */
    private array $metadata = [];

    /**
     * Every event that opens an intent carries them, but {@see failedFromState()}
     * can build a failure off a partially-applied aggregate, where reading an
     * uninitialised typed property would throw instead of recording the failure.
     * So `$description` defaults to empty, and `$merchantDescriptor` is read only
     * through {@see merchantDescriptor()}, which lazily defaults it — never read
     * `$this->merchantDescriptor` directly.
     */
    private MerchantDescriptor $merchantDescriptor;

    private string $description = '';

    private ?Challenge $challenge = null;

    private ?ChallengeResult $challengeResult = null;

    private PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated;

    /** @var array<string, Refund> indexed by RefundId string */
    private array $refunds = [];

    #[Override]
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

    public function billingAddress(): BillingAddress
    {
        return $this->billingAddress;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @psalm-suppress RedundantPropertyInitializationCheck the property is set by the
     *   opening event, not by a constructor, and a stream opened before merchant
     *   descriptors existed has none — which is exactly what this backfills.
     */
    public function merchantDescriptor(): MerchantDescriptor
    {
        return $this->merchantDescriptor ??= MerchantDescriptor::none();
    }

    public function description(): string
    {
        return $this->description;
    }

    public function challenge(): ?Challenge
    {
        return $this->challenge;
    }

    public function challengeResult(): ?ChallengeResult
    {
        return $this->challengeResult;
    }

    public function initiation(): PaymentInitiation
    {
        return $this->initiation;
    }

    /**
     * Remaining amount available for refund. Computed: the amount actually
     * captured minus the sum of every refund that has settled successfully.
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

    public static function create(
        CreatePaymentIntentCommand $command,
        CreatePort $port,
        PaymentIntentFirewallPort $firewall,
        ?ChallengePort $challenges = null,
    ): self {
        $command->amount()->isPositive() || throw InvalidPaymentIntent::nonPositiveAmount();
        $command->instrument()->isValid() || throw InvalidPaymentIntent::unusablePaymentSource();

        // A hosted payment is a redirect to the gateway's own page, so there is
        // no instrument on our side to authorize now and capture later. Left
        // unchecked this reaches the port, which routes every non-Immediate
        // capture method to `authorize()` — a path no gateway implements for
        // hosted — and the refusal comes back looking like an acquirer decline.
        if ($command->instrument() instanceof HostedPayment && $command->captureMethod() !== CaptureMethod::Immediate) {
            throw InvalidPaymentIntent::hostedPaymentRequiresImmediateCapture($command->captureMethod()->value);
        }

        $self = new self($command->paymentIntentId());

        // Inspect before spending a gateway call: a rejected payment never
        // reaches the acquirer, it parks for authentication instead.
        $decision = $self->firewallDecision($command, $firewall);
        $evidence = $command->challengeResult();

        if ($decision !== null && ! $decision->permits()) {
            // A rule rejected this payment. Authentication cannot answer that — the firewall is
            // not asking who the cardholder is, it has decided the payment must not happen — so
            // holding it for a step-up would wait on evidence that could never satisfy the rule,
            // and would send a cardholder somewhere for nothing. Evidence already in hand does
            // not soften it either: a denial is not a question.
            if ($decision->isDenied()) {
                $self->recordThat(new PaymentIntentFailed(
                    $command->amount(),
                    $command->instrument(),
                    $command->captureMethod(),
                    $command->billingAddress(),
                    $command->metadata(),
                    $command->merchantDescriptor(),
                    $command->description(),
                    self::firewallRefusalReason($decision),
                    ErrorCode::Blocked,
                    $evidence,
                    $command->initiation(),
                ));

                return $self;
            }

            // Authentication is required, and this is where a server-to-server payment differs
            // from a checkout. There is no cardholder session here to conduct one in — no browser
            // to fingerprint, nothing to render an ACS page into, and on a stored instrument no
            // pan the caller could authenticate with even if there were. Starting an
            // authentication we cannot finish would hold the payment open on something no client
            // can act on, which is the state this package spent a while removing.
            //
            // So: no evidence, no payment, said now and said in a form a program can branch on.
            // The caller runs the authentication through the endpoints that exist for it and
            // sends the payment again with the result.
            if ($evidence === null) {
                $self->recordThat(new PaymentIntentFailed(
                    $command->amount(),
                    $command->instrument(),
                    $command->captureMethod(),
                    $command->billingAddress(),
                    $command->metadata(),
                    $command->merchantDescriptor(),
                    $command->description(),
                    self::authenticationRequiredReason($decision),
                    ErrorCode::AuthenticationRequired,
                    null,
                    $command->initiation(),
                ));

                return $self;
            }

            // Evidence was presented, so it gets weighed rather than taken. This is the step that
            // keeps the whole arrangement from being a bypass: presenting a result is what carries
            // a payment past a step-up rule, and a well-formed result is indistinguishable from an
            // invented one by looking at it. The port checks it against the authentications this
            // service issued — for which card, for how much, and whether it has been spent — not
            // against what the request says about itself.
            $authentication = ($challenges ?? throw ChallengeCannotBeRaised::noPortInstalled($decision->reason))
                ->verify(new VerifyChallengeRequest(
                    paymentIntentId: $command->paymentIntentId(),
                    presented: $evidence,
                    amount: $command->amount(),
                    instrument: $command->instrument(),
                    reason: $decision->reason,
                ));

            if (! $authentication->wasPassed()) {
                $self->recordThat(new PaymentIntentFailed(
                    $command->amount(),
                    $command->instrument(),
                    $command->captureMethod(),
                    $command->billingAddress(),
                    $command->metadata(),
                    $command->merchantDescriptor(),
                    $command->description(),
                    self::challengeRefusalReason($authentication),
                    ErrorCode::AuthenticationFailed,
                    $evidence,
                    $command->initiation(),
                ));

                return $self;
            }

            // What proceeds is the provider's answer, not the caller's copy of it — that is the
            // evidence the acquirer will be shown, and asking is pointless if the reply is the
            // question.
            $evidence = $authentication->result;
        }

        try {
            $outcome = $port->create(new CreateRequest(
                paymentIntentId: $command->paymentIntentId(),
                amount: $command->amount(),
                instrument: $command->instrument(),
                captureMethod: $command->captureMethod(),
                billingAddress: $command->billingAddress(),
                challengeResult: $evidence,
                initiation: $command->initiation(),
            ));
        } catch (GatewayDeclinedException $e) {
            $self->recordThat(new PaymentIntentFailed(
                $command->amount(),
                $command->instrument(),
                $command->captureMethod(),
                $command->billingAddress(),
                $command->metadata(),
                $command->merchantDescriptor(),
                $command->description(),
                $e->reason,
                ErrorCode::GatewayDeclined,
                $evidence,
                $command->initiation(),
            ));

            return $self;
        }

        // Step-up required — branch off and wait for confirmChallenge().
        if ($outcome->challenge !== null) {
            $self->recordThat(new PaymentIntentRequiresAction(
                $command->amount(),
                $command->instrument(),
                $command->captureMethod(),
                $command->billingAddress(),
                $command->metadata(),
                $command->merchantDescriptor(),
                $command->description(),
                $outcome->challenge,
                $command->initiation(),
            ));

            return $self;
        }

        $self->chargeOrAuthorize(
            $command->captureMethod(),
            $command->amount(),
            $command->instrument(),
            $command->billingAddress(),
            $command->metadata(),
            $command->merchantDescriptor(),
            $command->description(),
            // The resolved evidence, not the command's copy of it. Where an authentication ran
            // during this call, what claims the liability shift is what the provider answered.
            $evidence,
            $command->initiation(),
            $outcome->convertedAmount,
        );

        return $self;
    }

    /**
     * Opens an intent the gateway already holds, without going near a port.
     *
     * {@see PaymentIntentImported} has had an applier since the settlement-file
     * import, but no way to record it — callers reached past the aggregate and
     * hand-built the message. This is that entry point, and the only creation
     * path that does not call the gateway: {@see create()} exists to *make* an
     * intent and spends a `CreatePort::create()` doing it, which against an
     * intent the gateway opened moments ago would take the money twice.
     *
     * No firewall either. Inspection decides whether to *place* a payment; this
     * one is already placed, and refusing it here would leave the gateway
     * holding an authorization the aggregate denies exists.
     */
    public function import(
        Money $amount,
        PaymentIntentStatus $status,
        PaymentInstrument $instrument,
        CaptureMethod $captureMethod,
        BillingAddress $billingAddress,
        MerchantDescriptor $merchantDescriptor,
        string $description,
    ): void {
        // Guards on the typed property being uninitialised rather than on the
        // version, so it holds for a caller that assembled the aggregate some
        // other way. Importing over a live intent would rewrite its amount and
        // instrument from a stale export and lose whatever it had progressed to.
        /** @psalm-suppress RedundantPropertyInitializationCheck no constructor sets it; an unopened aggregate genuinely has no status. */
        isset($this->status) && throw InvalidPaymentIntent::alreadyExists($this->aggregateRootId());

        $this->recordThat(new PaymentIntentImported(
            $amount,
            $status,
            $instrument,
            $captureMethod,
            $billingAddress,
            $merchantDescriptor,
            $description,
        ));
    }

    public function capture(CapturePaymentIntentCommand $command, CapturePort $port): void
    {
        $this->status === PaymentIntentStatus::Authorized || throw PaymentIntentCannotBeCaptured::withStatus($this->status);
        $this->captureMethod !== CaptureMethod::Immediate || throw PaymentIntentCannotBeCaptured::immediate();

        // No catch, unlike create / confirmChallenge / cancel. Capturing an
        // authorization has no business failure mode — the money was reserved
        // when it was authorized — so a failure here is infrastructural and the
        // answer is to retry, not to bury the intent in `Failed`. Recording a
        // failure would also be a lie the aggregate cannot take back: the funds
        // may well still be held.
        $outcome = $port->capture(new CaptureRequest(
            paymentIntentId: $this->aggregateRootId(),
            amount: $command->amount(),
            // Both from our own state rather than from the command: what was held and
            // what it was held on are facts about this intent, not a caller's choice,
            // and a caller free to restate them could contradict them.
            authorizedAmount: $this->amount,
            instrument: $this->instrument,
        ));

        $this->recordThat(new PaymentIntentCaptured($command->amount(), $outcome->convertedAmount));
    }

    public function cancel(CancelPaymentIntentCommand $command, CancelPort $port): void
    {
        static $cancelable = [PaymentIntentStatus::Authorized, PaymentIntentStatus::RequiresAction];
        in_array($this->status, $cancelable, true) || throw PaymentIntentCannotBeCancelled::withStatus($this->status);

        try {
            $port->cancel(new CancelRequest($this->aggregateRootId()));
        } catch (GatewayDeclinedException $e) {
            $this->recordThat($this->failedFromState($e->reason, ErrorCode::GatewayDeclined));

            return;
        }

        $this->recordThat(new PaymentIntentCancelled($command->reason()));
    }

    /**
     * Resolves the authentication this payment was parked on, and completes the
     * payment with it.
     *
     * Takes a port for the same reason every other operation here does: completing
     * the payment may cost a gateway call, and whether it does is the port's
     * business rather than the aggregate's. The two cases are two implementations
     * of {@see ConfirmChallengePort} — the gateway raised the challenge and
     * settled it itself, or we raised it and only now is there a payment to place.
     *
     * Not {@see CreatePort}: where the gateway raised the challenge it had already
     * opened the payment, and there is nothing to create. Before the port was a
     * parameter this method could only record, so the case where we raise the
     * challenge booked a charge the gateway had never been asked for.
     *
     * The billing address is not null here: the only states this runs in were
     * reached by an event that carries a required one.
     */
    public function confirmChallenge(ChallengeResult $result, ConfirmChallengePort $port): void
    {
        $this->status === PaymentIntentStatus::RequiresAction || throw PaymentIntentChallengeNotPending::withStatus($this->status);

        $failureReason = $result->accept(new ChallengeFailureReasonExtractor);

        if ($failureReason !== null) {
            $this->recordThat($this->failedFromState($failureReason, ErrorCode::AuthenticationFailed, $result));

            return;
        }

        // Coherent before acted upon. A result claiming success without the artefact
        // that makes it successful is nobody's refusal, so it must not be recorded as
        // one — but neither may it be charged on, because what would be stored as the
        // evidence for the liability shift proves nothing. Thrown, and before the port.
        if (($missing = $result->accept(new MissingChallengeEvidenceExtractor)) !== null) {
            throw InvalidPaymentIntent::challengeResultCarriesNoEvidence($missing);
        }

        try {
            $outcome = $port->confirm(new ConfirmChallengeRequest(
                paymentIntentId: $this->aggregateRootId(),
                challengeResult: $result,
                challenge: $this->challenge,
                amount: $this->amount,
                instrument: $this->instrument,
                captureMethod: $this->captureMethod,
                billingAddress: $this->billingAddress,
                initiation: $this->initiation,
            ));
        } catch (GatewayDeclinedException $e) {
            $this->recordThat($this->failedFromState($e->reason, ErrorCode::GatewayDeclined, $result));

            return;
        }

        $this->chargeOrAuthorize(
            $this->captureMethod,
            $this->amount,
            $this->instrument,
            $this->billingAddress,
            $this->metadata,
            $this->merchantDescriptor(),
            $this->description,
            $result,
            $this->initiation,
            $outcome->convertedAmount,
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
            $this->recordThat(new RefundFailed($command->refundId(), $command->amount(), $e->reason, ErrorCode::GatewayDeclined, $command->retryInstrument()));

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
     * Put the payment through its firewall chain, or null when there is nothing to inspect.
     *
     * The decision is returned whole rather than reduced to a yes/no, because its three actions
     * mean three different things and only the caller can act on them: a rejection ends the
     * payment, a demand for authentication holds it up, and only an allow proceeds.
     *
     * What this deliberately no longer does is manufacture a challenge. It used to answer a
     * non-permitting decision with `new ThreeDSChallenge(transactionId: $paymentIntentId)`, a
     * fabrication on two counts: a {@see Challenge} is evidence that a handoff to an ACS has
     * ALREADY happened and carries what the client needs to render it, and the identifier it
     * named was the payment intent's rather than any 3DS transaction's. Raising a real one is
     * {@see ChallengePort}'s, consulted by {@see create()} once this has decided.
     *
     * ## Two things that used to skip inspection and no longer do
     *
     * Both were safe under a firewall whose only power was to demand a step-up, and both became
     * bypasses the moment it could refuse a payment outright:
     *
     *  - a merchant-initiated payment. It has no cardholder, so a step-up cannot be carried out
     *    — but a rule that decided this payment must not happen says nothing about who is
     *    present, and skipping the chain meant a denial went unasked for. The impossible part is
     *    handled where it arises: a `Challenge` verdict on an MIT is refused by the port. Chains
     *    that should not demand one of unattended traffic say so with the
     *    `payment_intent.initiation` fact.
     *  - a payment arriving with a finished 3DS authentication. That skip reasoned that the
     *    liability shift is already claimed, which is true and beside the point: the result comes
     *    from the caller, the coherence check on it asks whether its fields agree and not whether
     *    the authentication happened, so attaching a well-formed one was enough to walk past
     *    every deny rule in the chain. Now the chain runs first, and evidence is something
     *    {@see ChallengePort::verify()} weighs only once a chain has asked for authentication.
     *
     * One skip remains: a non-card instrument. It is not a policy choice but the shape of
     * {@see PaymentIntentFirewallRequest}, which requires a card summary because the fact
     * vocabulary is built around one — so a hosted payment or a bare token has nothing to match
     * on, including for rules about amount or gateway that would otherwise apply. Worth removing
     * when the request can describe an instrument it cannot summarise.
     *
     * A missing connection is deliberately NOT a skip: the chain still runs and rules leaning on
     * connection facts merely fail to match. Skipping because an input is absent would let a
     * forgotten field bypass the firewall.
     */
    private function firewallDecision(
        CreatePaymentIntentCommand $command,
        PaymentIntentFirewallPort $firewall,
    ): ?FirewallDecision {
        $card = CardSummaryExtractor::from($command->instrument());

        if ($card === null) {
            return null;
        }

        return $firewall->evaluate(new PaymentIntentFirewallRequest(
            amount: $command->amount(),
            card: $card,
            billing: $command->billingAddress(),
            connection: $command->connection(),
            paymentIntentId: $command->paymentIntentId(),
            gatewayId: $command->gatewayId(),
            initiation: $command->initiation(),
        ));
    }

    /**
     * Why a denied payment was refused.
     *
     * {@see FirewallDecision::$reason} is a breadcrumb — a rule identifier, "no rule matched
     * (whitelist)" — and its own docblock forbids parsing it. Recording it is the audit use it
     * exists for, and it is prefixed so a reader can tell our own policy from an acquirer's
     * answer: nothing left this process and no issuer was consulted.
     */
    private static function firewallRefusalReason(FirewallDecision $decision): string
    {
        return $decision->reason === null || $decision->reason === ''
            ? 'Refused by the payment firewall.'
            : "Refused by the payment firewall: {$decision->reason}";
    }

    /**
     * Why a payment was refused for want of an authentication nobody could start here.
     *
     * Phrased for the operator reading a failed payment back, and paired with
     * {@see ErrorCode::AuthenticationRequired}, which is the part a caller acts on. The chain's
     * own breadcrumb rides along because knowing WHICH rule asked is the difference between a
     * merchant fixing a rule and a merchant retrying forever.
     */
    private static function authenticationRequiredReason(FirewallDecision $decision): string
    {
        return $decision->reason === null || $decision->reason === ''
            ? 'Authentication is required before this payment can be placed.'
            : "Authentication is required before this payment can be placed: {$decision->reason}";
    }

    /**
     * Why an authentication ended the payment.
     *
     * Prefixed like the firewall's own refusal and for the same reason: nothing left this
     * process and no acquirer was consulted, so a bare reason read back later would look like an
     * issuer's decline of a payment that was never presented to one.
     */
    private static function challengeRefusalReason(ChallengeOutcome $outcome): string
    {
        return $outcome->reason === null || $outcome->reason === ''
            ? 'Authentication was refused.'
            : "Authentication was refused: {$outcome->reason}";
    }

    private function chargeOrAuthorize(
        CaptureMethod $captureMethod,
        Money $amount,
        PaymentInstrument $instrument,
        BillingAddress $billingAddress,
        array $metadata,
        MerchantDescriptor $merchantDescriptor,
        string $description,
        ?ChallengeResult $challengeResult,
        PaymentInitiation $initiation,
        ?Money $convertedAmount = null,
    ): void {
        if ($captureMethod === CaptureMethod::Immediate) {
            $this->recordThat(new PaymentIntentCharged($amount, $instrument, $captureMethod, $billingAddress, $metadata, $merchantDescriptor, $description, $challengeResult, $convertedAmount, $initiation));
        } else {
            // Authorize-only holds funds without settlement, so no FX has
            // occurred yet — the converted amount surfaces on capture.
            $this->recordThat(new PaymentIntentAuthorized($amount, $instrument, $captureMethod, $billingAddress, $metadata, $merchantDescriptor, $description, $challengeResult, $initiation));
        }
    }


    private function failedFromState(string $reason, ErrorCode $code, ?ChallengeResult $challengeResult = null): PaymentIntentFailed
    {
        return new PaymentIntentFailed(
            $this->amount,
            $this->instrument,
            $this->captureMethod,
            $this->billingAddress,
            $this->metadata,
            $this->merchantDescriptor(),
            $this->description,
            $reason,
            $code,
            $challengeResult ?? $this->challengeResult,
            $this->initiation,
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

    #[Override]
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
            'merchant_descriptor' => (string) $this->merchantDescriptor(),
            'description' => $this->description,
            'billing_address' => $this->billingAddress->toArray(),
            'challenge' => $this->challenge === null ? null : ChallengeArraySerializer::toArray($this->challenge),
            'challenge_result' => $this->challengeResult === null ? null : ChallengeResultArraySerializer::toArray($this->challengeResult),
            'initiation' => $this->initiation->value,
            'refunds' => array_map(fn (Refund $r) => $r->toSnapshot(), array_values($this->refunds)),
        ];
    }

    #[Override]
    protected static function reconstituteFromSnapshotState(AggregateRootId $id, $state): AggregateRootWithSnapshotting
    {
        // EventSauce's signature is the widest id type; a snapshot of this aggregate can
        // only carry its own.
        assert($id instanceof PaymentIntentId);

        $self = new self($id);
        $self->status = PaymentIntentStatus::from($state['status']);
        $currency = new Currency($state['currency']);
        $self->amount = new Money($state['amount'], $currency);
        $self->captured = isset($state['captured']) ? new Money($state['captured'], $currency) : null;
        $self->instrument = PaymentInstrumentFactory::fromPayload($state['instrument']);
        $self->captureMethod = CaptureMethod::from($state['capture_method']);
        $self->metadata = $state['metadata'] ?? [];
        $self->merchantDescriptor = new MerchantDescriptor($state['merchant_descriptor'] ?? '');
        $self->description = $state['description'] ?? '';
        // Refused rather than defaulted: a snapshot with no billing address predates the
        // field being required, and quietly substituting one would put an address the
        // cardholder never gave into AVS and reporting.
        $self->billingAddress = isset($state['billing_address'])
            ? BillingAddress::fromArray($state['billing_address'])
            : throw new RuntimeException("Payment intent snapshot '{$id->toString()}' carries no billing address.");
        $self->challenge = isset($state['challenge']) ? ChallengeArraySerializer::fromArray($state['challenge']) : null;
        $self->challengeResult = isset($state['challenge_result']) ? ChallengeResultArraySerializer::fromArray($state['challenge_result']) : null;
        $self->initiation = PaymentInitiation::from($state['initiation'] ?? PaymentInitiation::CardholderInitiated->value);

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
        $this->merchantDescriptor = $event->merchantDescriptor;
        $this->description = $event->description;
        if ($event->status === PaymentIntentStatus::Charged) {
            $this->captured = $event->amount;
        }
    }

    protected function applyPaymentIntentAuthorized(PaymentIntentAuthorized $event): void
    {
        $this->status = PaymentIntentStatus::Authorized;
        $this->initiation = $event->initiation;
        $this->amount = $event->amount;
        $this->instrument = $event->instrument;
        $this->captureMethod = $event->captureMethod;
        $this->billingAddress = $event->billingAddress;
        $this->metadata = $event->metadata;
        $this->merchantDescriptor = $event->merchantDescriptor;
        $this->description = $event->description;
        $this->challengeResult = $event->challengeResult;
        $this->challenge = null;
    }

    protected function applyPaymentIntentCharged(PaymentIntentCharged $event): void
    {
        $this->status = PaymentIntentStatus::Charged;
        $this->initiation = $event->initiation;
        $this->amount = $event->amount;
        $this->instrument = $event->instrument;
        $this->captureMethod = $event->captureMethod;
        $this->billingAddress = $event->billingAddress;
        $this->metadata = $event->metadata;
        $this->merchantDescriptor = $event->merchantDescriptor;
        $this->description = $event->description;
        $this->challengeResult = $event->challengeResult;
        $this->challenge = null;
        $this->captured = $event->amount;
    }

    protected function applyPaymentIntentRequiresAction(PaymentIntentRequiresAction $event): void
    {
        $this->status = PaymentIntentStatus::RequiresAction;
        $this->initiation = $event->initiation;
        $this->amount = $event->amount;
        $this->instrument = $event->instrument;
        $this->captureMethod = $event->captureMethod;
        $this->billingAddress = $event->billingAddress;
        $this->metadata = $event->metadata;
        $this->merchantDescriptor = $event->merchantDescriptor;
        $this->description = $event->description;
        $this->challenge = $event->challenge;
    }

    protected function applyPaymentIntentFailed(PaymentIntentFailed $event): void
    {
        $this->status = PaymentIntentStatus::Failed;
        $this->initiation = $event->initiation;
        $this->amount = $event->amount;
        $this->instrument = $event->instrument;
        $this->captureMethod = $event->captureMethod;
        $this->billingAddress = $event->billingAddress;
        $this->metadata = $event->metadata;
        $this->merchantDescriptor = $event->merchantDescriptor;
        $this->description = $event->description;
        $this->challengeResult = $event->challengeResult;
        $this->challenge = null;
    }

    protected function applyPaymentIntentCancelled(): void
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
