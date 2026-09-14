<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute;

use DateTimeImmutable;
use DateTimeInterface;
use EventSauce\EventSourcing\AggregateRootBehaviour;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Snapshotting\AggregateRootWithSnapshotting;
use EventSauce\EventSourcing\Snapshotting\SnapshottingBehaviour;
use Money\Currency;
use Money\Money;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\Command\AcceptDisputeCommand;
use Techork\PaymentService\Domain\Dispute\Command\AttachDisputeEvidenceCommand;
use Techork\PaymentService\Domain\Dispute\Command\ChangeDisputeDeadlineCommand;
use Techork\PaymentService\Domain\Dispute\Command\ChangeDisputeStageCommand;
use Techork\PaymentService\Domain\Dispute\Command\ChangeDisputeStatusCommand;
use Techork\PaymentService\Domain\Dispute\Command\ConfirmEvidenceUploadCommand;
use Techork\PaymentService\Domain\Dispute\Command\ExpireDisputeCommand;
use Techork\PaymentService\Domain\Dispute\Command\OpenDisputeCommand;
use Techork\PaymentService\Domain\Dispute\Command\RecordDisputeFeeCommand;
use Techork\PaymentService\Domain\Dispute\Command\RejectDisputeEvidenceUploadCommand;
use Techork\PaymentService\Domain\Dispute\Command\SubmitDisputeEvidenceCommand;
use Techork\PaymentService\Domain\Dispute\Command\UploadDisputeEvidenceCommand;
use Techork\PaymentService\Domain\Dispute\Event\DisputeAccepted;
use Techork\PaymentService\Domain\Dispute\Event\DisputeDeadlineChanged;
use Techork\PaymentService\Domain\Dispute\Event\DisputeFeeRecorded;
use Techork\PaymentService\Domain\Dispute\Event\DisputeOpened;
use Techork\PaymentService\Domain\Dispute\Event\DisputeResolved;
use Techork\PaymentService\Domain\Dispute\Event\DisputeStageChanged;
use Techork\PaymentService\Domain\Dispute\Event\DisputeStatusChanged;
use Techork\PaymentService\Domain\Dispute\Event\EvidenceAttached;
use Techork\PaymentService\Domain\Dispute\Event\EvidenceSubmitted;
use Techork\PaymentService\Domain\Dispute\Event\EvidenceUploadConfirmed;
use Techork\PaymentService\Domain\Dispute\Exception\DisputeCannotChangeStatus;
use Techork\PaymentService\Domain\Dispute\Exception\DisputeCannotChangeSubmissionState;
use Techork\PaymentService\Domain\Dispute\Exception\InvalidDispute;
use Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeActionSet;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeFee;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceRequirements;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;
use Techork\PaymentService\Domain\Dispute\ValueObject\RespondDisputeAction;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * A dispute raised against one of our payments: what the network says, what we are doing about it,
 * and the trail that makes a repeated delivery recognisable as one.
 *
 * ## Two axes, and neither drives the other
 *
 * {@see DisputeStatus} is the provider's view of the case; {@see SubmissionState} is our view of
 * our own response. They move on different clocks: Nuvei sits in `UPLOAD_PENDING` with a callback
 * outstanding while the case reads `NEEDS_RESPONSE`, and ConnexPay has an operator claiming a
 * portal submission while `HasResponse` is still false. No method here advances one because the
 * other moved, and that is a property of the code rather than a convention — the submission
 * methods below touch `$submissionState` and never `$status`, and vice versa. Folding them into
 * one axis would have meant rewriting these invariants the first time a submission adapter had an
 * opinion.
 *
 * ## Two kinds of command, and why the difference is visible
 *
 * A fact a provider stated arrives with a {@see \Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal}
 * — the provider's own key for that delivery, which is the whole of the idempotency key — and every
 * one of those methods begins by asking whether that delivery has already been applied. A fact *we*
 * produced (staging evidence, sending a response, conceding, expiring a window nobody acted in) has
 * no delivery behind it, carries an explicit moment instead, and is idempotent by state: a case
 * already accepted has nothing to record, which is what the state check does where a key would have
 * done for the other kind.
 *
 * ## No clock, and no gateway field
 *
 * The aggregate reads no clock. Expiry arrives as an explicit command from the application, and a
 * computed deadline is a value someone else worked out. The gateway is not a field, and is not part
 * of the idempotency key either: it is ambient context that arrives on the message headers
 * (`GatewayIdMessageDecorator`), and it belongs to the layer that already holds it. A case lives at
 * one gateway account for its whole life, so within one stream a gateway component could never tell
 * two keys apart — {@see \Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal} sets out
 * the whole of it.
 *
 * ## The guard is the table
 *
 * Every move is checked against `allowedTransitions()` on the two enums rather than against a list
 * kept here, so a transition cannot become legal by being written into this class and not into the
 * table. Anything the table does not list throws. Two things are *not* table questions and are
 * handled here by name:
 *
 *  - a repeat — re-applying the status or stage a case already holds, and a `lost` arriving after
 *    our own `ACCEPTED` ({@see self::isDeliberateAcceptanceReplayed()}) — records nothing at all;
 *  - `ACCEPTED` is reachable only through {@see self::accept()}, because it records *why* we
 *    stopped fighting and no provider reports that (see {@see DisputeAccepted}).
 *
 * ## What this aggregate deliberately does not have
 *
 * No ports, and no gateway call. The action model is Domain too, and it runs the other way round
 * from what "the aggregate depends on the model" would suggest: {@see self::availableDisputeActions()}
 * is where the model is *produced*, so the dependency is two-way inside this package — the model's
 * types carry what this class holds, and this class states which of them the case admits. What stays
 * out is the provider: which actions a *gateway* can execute is capability, the live read is the one
 * fact this class cannot see, and both are applied above as subtractions. No fee total either:
 * fees point in two directions ({@see \Techork\PaymentService\Domain\Dispute\ValueObject\FeeType})
 * and summing them here would silently lose the difference between a fee we paid and one we got
 * back, which is the whole reason the list exists. And no history: the events *are* it, so a list
 * of the case's visits to each stage would be a second copy of what {@see DisputeOpened} and
 * {@see DisputeStageChanged} already state — carried into every snapshot, read by nothing here, and
 * out of date the moment a reader trusted it over the stream.
 */
final class DisputeAggregate implements AggregateRootWithSnapshotting
{
    /** @use AggregateRootBehaviour<DisputeId> */
    use AggregateRootBehaviour;
    use SnapshottingBehaviour;

    private PaymentIntentId $paymentIntentId;

    private DisputeStage $stage;

    private DisputeStatus $status;

    /**
     * Defaulted rather than left uninitialised, because it is the one axis whose opening value is
     * meaningful before anything has happened: a case nobody has answered is `NONE`, and every
     * aggregate reconstituted from a stream that predates the field reads the same way.
     */
    private SubmissionState $submissionState = SubmissionState::None;

    private DisputeReason $reason;

    private Money $disputedAmount;

    /** @var list<DisputeFee> */
    private array $fees = [];

    private ?DateTimeImmutable $deadlineAt = null;

    /**
     * The facts currently staged for submission — {@see EvidenceType} names, never their content.
     *
     * The aggregate is told *what* it is answering with and not what the answers say: the bytes go
     * to the provider through a submission port, and a base64 PDF replayed into every snapshot for
     * the rest of the case's life would buy this class no answer it asks.
     *
     * @var list<EvidenceType>
     */
    private array $stagedEvidence = [];

    /**
     * What the last submission out actually carried, which staging alone cannot say: `DRAFT` holds
     * what we meant to send, and this holds what left.
     *
     * @var list<EvidenceType>
     */
    private array $submittedEvidence = [];

    /**
     * The most recent provider delivery applied, as the provider's own key for it.
     *
     * **The most recent, never a set of every key ever seen.** An unchanged case re-polled emits
     * nothing; a case that returns to an earlier combination of values legitimately returns to an
     * earlier key, and a set would suppress that transition as a duplicate when it is precisely the
     * change the provider mappings exist to report. The value is opaque here and this class never
     * interprets it: for ConnexPay it is a hash of the case's current state, and a component that
     * read anything out of it would be wrong for at least one provider. Where each provider's key
     * comes from is that provider's business — `DisputeSignal` lists the three shapes, and the
     * hashing contract belongs to the ConnexPay package that computes it.
     *
     * The key alone is not the whole of what is remembered: it says *which* delivery, and
     * {@see self::$appliedFactsOfLastDelivery} says which of that delivery's statements were
     * applied. Both halves are needed, and the guard explains why.
     */
    private ?string $lastAppliedProviderEventKey = null;

    /**
     * Which of that delivery's facts were applied — the other half of the guard.
     *
     * One delivery states several facts at once. A Nuvei Chargeback DMN carries the stage, the
     * status and the due date under a single `DisputeEventId`; ConnexPay's poller hands over the
     * whole case under a single state hash; a Stripe `charge.dispute.updated` carries a status and,
     * often, a new response deadline. The recorder applies those one call at a time, so against the
     * key alone the first call would record and each later one would be read as a redelivery of it
     * — a status the network has just decided, or a deadline, dropped with nothing recorded and
     * nothing raised. {@see self::hasAlreadyApplied()} states the rule in full.
     *
     * Cleared whenever a *different* delivery starts, so what this holds is the last delivery's
     * facts and never a history of them. The refusal the key makes of a set of every key it has
     * seen is made here too, for the same reason and with the same consequence if it were dropped.
     *
     * @var list<string>
     */
    private array $appliedFactsOfLastDelivery = [];

    /**
     * The axis each guard-and-remember call names.
     *
     * A fact is one thing a delivery can state that this aggregate records separately, which is the
     * same division the events already make: one event per axis, so one fact per event. Where the
     * fact and the axis could part company — fees — the constant below states what is done instead
     * of guessing.
     */
    private const string FACT_OPENED = 'opened';
    private const string FACT_STAGE = 'stage';
    private const string FACT_STATUS = 'status';
    private const string FACT_DEADLINE = 'deadline';
    private const string FACT_EVIDENCE_ATTACHED = 'evidence_attached';
    private const string FACT_EVIDENCE_SUBMITTED = 'evidence_submitted';
    private const string FACT_EVIDENCE_UPLOAD_CONFIRMED = 'evidence_upload_confirmed';

    /**
     * A fee's fact id, and the one place the fact is the axis when a fee's identity is not.
     *
     * Every other axis here can be stated once by one delivery, so fact and axis are the same word.
     * Fees are where the two could differ, and the difference is refused rather than taken: **a fee
     * stated under a delivery that was already applied is dropped even when it is a fee that
     * delivery did not carry**, because the aggregate cannot tell that apart from a redelivery
     * naming a different fee, and the second reading of a key must not be able to double a case's
     * fees on the strength of a payload the provider sent once.
     *
     * What that costs is an open question rather than an assumption: if a provider really does state
     * two fees in one delivery, the second is dropped here and this constant has to become the fee's
     * own identity instead. Two things bound the damage until that is settled — the fee's value
     * guard, `(type, amount, chargedAt)`, catches the same fee told twice across *different*
     * deliveries, and A4 reads money from settlement data rather than from this list either way.
     */
    private const string FACT_FEE = 'fee';

    /** When the provider acknowledged the file itself, as opposed to the response. See the applier. */
    private ?DateTimeImmutable $evidenceUploadConfirmedAt = null;

    /** When the provider acknowledged the response, which is the only way `CONFIRMED` is written. */
    private ?DateTimeImmutable $submissionConfirmedAt = null;

    #[Override]
    public function aggregateRootId(): DisputeId
    {
        return DisputeId::fromString($this->aggregateRootId->toString());
    }

    public function paymentIntentId(): PaymentIntentId
    {
        return $this->paymentIntentId;
    }

    public function stage(): DisputeStage
    {
        return $this->stage;
    }

    public function status(): DisputeStatus
    {
        return $this->status;
    }

    public function submissionState(): SubmissionState
    {
        return $this->submissionState;
    }

    public function reason(): DisputeReason
    {
        return $this->reason;
    }

    public function disputedAmount(): Money
    {
        return $this->disputedAmount;
    }

    /** @return list<DisputeFee> */
    public function fees(): array
    {
        return $this->fees;
    }

    public function deadlineAt(): ?DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    /** @return list<EvidenceType> */
    public function stagedEvidence(): array
    {
        return $this->stagedEvidence;
    }

    /** @return list<EvidenceType> */
    public function submittedEvidence(): array
    {
        return $this->submittedEvidence;
    }

    public function lastAppliedProviderEventKey(): ?string
    {
        return $this->lastAppliedProviderEventKey;
    }

    /**
     * Which facts of that delivery were applied — the other half of the guard, and the half that
     * says a delivery stating three things is not the same as one stating the first of them.
     *
     * @return list<string>
     */
    public function appliedFactsOfLastDelivery(): array
    {
        return $this->appliedFactsOfLastDelivery;
    }

    public function evidenceUploadConfirmedAt(): ?DateTimeImmutable
    {
        return $this->evidenceUploadConfirmedAt;
    }

    public function submissionConfirmedAt(): ?DateTimeImmutable
    {
        return $this->submissionConfirmedAt;
    }

    /**
     * What this case still offers, as the domain states it: the ceiling a provider may narrow and
     * must never widen.
     *
     * ## Why the rule is here and not in an adapter
     *
     * Which actions a case admits is a fact about the case — its stage, its status, whether a window
     * is still running — and none of that is the provider's to say. It was stated twice, once per
     * adapter, which is a rule that exists in two copies and in no document. Here it exists once, and
     * the providers differ in what they *subtract* from it.
     *
     * ## The predicate, and each clause's reason
     *
     * ```
     * effectiveRespondBy = $respondBy ?? $this->deadlineAt()
     *
     * respond := status === NEEDS_RESPONSE && effectiveRespondBy !== null
     * accept  := status === NEEDS_RESPONSE && stage !== INQUIRY && effectiveRespondBy !== null
     * ```
     *
     * **Stated positively on `NEEDS_RESPONSE`**, never by exclusion through
     * {@see DisputeStatus::isTerminal()}: that answers false for `LOST`, which may still become `WON`
     * and is nonetheless a case nobody is waiting on us to answer. `UNDER_REVIEW` — the network has
     * the case, and nothing waits on us while it does — yields nothing for the same reason.
     *
     * **The concession is withheld on `INQUIRY`.** An inquiry is a pre-chargeback request for
     * information, and closing one is not the act of conceding a chargeback: the answer we would read
     * back is an expiry rather than a concession, so offering the irreversible call there would be
     * offering one whose own outcome we could not recognise as ours. Withholding it costs the
     * operator a portal visit; offering it wrongly costs the disputed sum.
     *
     * The clause is `!== INQUIRY` and deliberately not `=== CHARGEBACK`: a case escalated to
     * pre-arbitration is still one we may concede, and a predicate naming the single stage would drop
     * the concession the moment a network escalates. {@see DisputeStage::Arbitration} is admitted by
     * this rule and has no producer anywhere — a rule that *admits* a stage does not *assume* a case
     * arrives there, and the guards that keep arbitration out live in {@see self::open()} and
     * {@see self::changeStage()}.
     *
     * **Both clauses require a deadline**, because both {@see RespondDisputeAction} and
     * {@see AcceptDisputeAction} carry a non-nullable one and an action with no window is a task
     * nobody can be given. A case that is waiting on us with no deadline we hold therefore answers an
     * empty set — the one state where `isEmpty()` does not mean "not waiting on us"; see
     * {@see DisputeActionSet} for the guards that keep it rare.
     *
     * **`submissionState()` is deliberately not consulted.** It looks like it belongs — we have filed
     * a response, so surely there is nothing to do — but the same state means opposite things at two
     * providers: at Stripe it says an API call of ours filed the response and a second one is money,
     * while at ConnexPay it records an operator's word about a portal submission the portal never
     * acknowledges, and withdrawing the link then would take away the one place the mistake can be
     * found while the window still runs. One state cannot carry two answers, so that veto stays in
     * the adapters, which are the layer that knows how our filing travelled.
     *
     * ## The three arguments, and why one of them falls back where the others do not
     *
     * `$respondBy`, `$cardBrand` and `$reasonCode` are the live read's values, passed by a caller that
     * asked the provider at the moment it is showing the case — the window between deliveries is when
     * a case is lost by default, which is the whole reason that read exists. `$respondBy` falls back
     * to {@see self::deadlineAt()} because the recorded deadline is kept current by every delivery
     * that moves it; the pair does **not** fall back to {@see self::reason()}, because the reason is
     * written once as the case opens and never moves — it is frozen at the opening delivery, so a
     * pair the read does not state means no template rather than a stale one. The two halves travel
     * together and are read as one key: a brand from one source and a code from another would answer
     * a question neither source asked.
     *
     * ## It is a query
     *
     * A pure read of this case's own state. It spends no gateway call, holds no port and mutates
     * nothing, which is what lets a rule this opinionated live on the aggregate at all.
     */
    public function availableDisputeActions(
        ?DateTimeImmutable $respondBy = null,
        ?CardBrand $cardBrand = null,
        ?string $reasonCode = null,
    ): DisputeActionSet {
        $effectiveRespondBy = $respondBy ?? $this->deadlineAt;

        if ($this->status !== DisputeStatus::NeedsResponse || $effectiveRespondBy === null) {
            return DisputeActionSet::none();
        }

        $actions = [new RespondDisputeAction(
            requirements: $cardBrand === null || $reasonCode === null
                ? null
                : EvidenceRequirements::tryFor($cardBrand, $reasonCode),
            respondBy: $effectiveRespondBy,
        )];

        if ($this->stage !== DisputeStage::Inquiry) {
            $actions[] = new AcceptDisputeAction(
                disputedAmount: $this->disputedAmount,
                respondBy: $effectiveRespondBy,
            );
        }

        return DisputeActionSet::of($actions);
    }

    /**
     * Opens a case from the delivery that first described it, in whatever state that delivery
     * reported.
     *
     * A static factory rather than a method on a live aggregate, mirroring
     * `PaymentIntentAggregate::create()`: whether the case a delivery names has a stream already is
     * the application's question (it is the thing holding the reference table, which is what turns
     * the provider's reference into one of our ids), and a recorder that guessed wrong here would
     * append a second opening to an existing stream. F2's recorder is where that decision belongs.
     *
     * **It can open straight into a terminal state.** A backlog import, or a resolution that
     * arrives for a case we never saw, describes a case as already `WON`, `LOST`, `EXPIRED` or
     * `CLOSED`; refusing those would lose the case entirely, and the state is the provider's own
     * statement rather than our reading of it. `ACCEPTED` is the one exception, and it is refused
     * for the reason it is refused everywhere: no provider reports it.
     */
    public static function open(OpenDisputeCommand $command): self
    {
        $command->disputedAmount()->isPositive() || throw InvalidDispute::nonPositiveDisputedAmount($command->disputedAmount());
        $command->stage() !== DisputeStage::Arbitration || throw InvalidDispute::arbitrationHasNoProducer();
        $command->status() !== DisputeStatus::Accepted || throw InvalidDispute::acceptanceIsNotAProviderSignal();

        $self = new self($command->disputeId());

        $self->recordThat(new DisputeOpened(
            $command->paymentIntentId(),
            $command->stage(),
            $command->status(),
            $command->reason(),
            $command->disputedAmount(),
            $command->deadlineAt(),
            $command->providerCode(),
            $command->signal()->providerEventKey,
            $command->signal()->observedAt,
        ));

        return $self;
    }

    /**
     * The case moved a stage — inquiry to chargeback, chargeback to pre-arbitration.
     *
     * There is no table for stages, and none is invented here: the plan gives one for statuses and
     * one for submissions, and a third written from inference would refuse moves the networks
     * really make — a case re-escalating into a second chargeback, or an inquiry that turns out to
     * be a retrieval request. What *is* guarded is `ARBITRATION`, which has no producer at all (see
     * {@see DisputeStage} and {@see InvalidDispute::arbitrationHasNoProducer()}).
     *
     * Re-applying the stage a case already holds records nothing, even where the provider's cycle
     * code differs: a second `DisputeStageChanged` from the same stage to itself would say the same
     * thing twice, and a projection reading the stream could not tell a re-escalation from a
     * redelivery.
     *
     * The case's history is not kept here. It is a projection of this event and
     * {@see DisputeOpened}, and both carry the provider's cycle code that tells two visits to one
     * stage apart — a second copy inside the aggregate would be a second answer to a question the
     * stream already answers.
     */
    public function changeStage(ChangeDisputeStageCommand $command): void
    {
        $signal = $command->signal();

        if ($this->hasAlreadyApplied($signal->providerEventKey, self::FACT_STAGE)) {
            return;
        }

        $command->stage() !== DisputeStage::Arbitration || throw InvalidDispute::arbitrationHasNoProducer();

        if ($this->stage === $command->stage()) {
            return;
        }

        $this->recordThat(new DisputeStageChanged(
            $this->stage,
            $command->stage(),
            $command->providerCode(),
            $signal->providerEventKey,
            $signal->observedAt,
        ));
    }

    /**
     * The case moved on the status axis, as a provider's delivery reported it.
     *
     * `ACCEPTED` is refused here and reaches the aggregate only through {@see self::accept()}.
     */
    public function changeStatus(ChangeDisputeStatusCommand $command): void
    {
        $signal = $command->signal();

        if ($this->hasAlreadyApplied($signal->providerEventKey, self::FACT_STATUS)) {
            return;
        }

        $command->status() !== DisputeStatus::Accepted
            || throw DisputeCannotChangeStatus::acceptanceGoesThroughAccept($this->status);

        if (! $this->statusMayMoveTo($command->status())) {
            return;
        }

        $from = $this->status;

        if ($command->status()->isResolution()) {
            $this->recordThat(new DisputeResolved($from, $command->status(), $signal->providerEventKey, $signal->observedAt));

            return;
        }

        $this->recordThat(new DisputeStatusChanged($from, $command->status(), $signal->providerEventKey, $signal->observedAt));
    }

    /**
     * We concede the case.
     *
     * The only writer of `ACCEPTED`, and the reason it is a command of its own rather than a status
     * value: F7's `POST /v1/disputes/:id/close` tells the provider we have stopped fighting, and
     * the provider then reports the case as `lost` — because its API has no way to record *why*.
     * What the ledger needs is the why. See {@see DisputeAccepted}.
     */
    public function accept(AcceptDisputeCommand $command): void
    {
        if (! $this->statusMayMoveTo(DisputeStatus::Accepted)) {
            return;
        }

        $this->recordThat(new DisputeAccepted($this->status, null, $command->at()));
    }

    /**
     * The response window closed and nobody acted — the application's clock, not ours.
     *
     * The second of the two routes into `EXPIRED`, and the only place this aggregate is told a
     * moment that no provider stated. The table names `NEEDS_RESPONSE` as the only state it is
     * reachable from, and the guard is left exactly as the table states it
     * ({@see DisputeStatus::allowedTransitions()}): a case the network has taken under review is
     * not in a window we may declare closed on our own, and an expiry attempted there is refused
     * loudly rather than widened by inference.
     */
    public function expire(ExpireDisputeCommand $command): void
    {
        if (! $this->statusMayMoveTo(DisputeStatus::Expired)) {
            return;
        }

        $this->recordThat(new DisputeStatusChanged($this->status, DisputeStatus::Expired, null, $command->at()));
    }

    /**
     * The date the case must be answered by moved, or was stated for the first time.
     *
     * Whether the date is the provider's or one we worked out travels inside the value, so nothing
     * on the way in or out can separate them — an escalation prompt built on a derived date
     * presented as the network's word is a deadline that moves when a configuration constant does.
     */
    public function changeDeadline(ChangeDisputeDeadlineCommand $command): void
    {
        $signal = $command->signal();
        $signalKey = $signal?->providerEventKey;

        if ($signalKey !== null && $this->hasAlreadyApplied($signalKey, self::FACT_DEADLINE)) {
            return;
        }

        // Compared as instants rather than as renderings: a provider can restate one deadline with
        // a different UTC offset, and the rendered forms differ while the moment does not.
        if ($this->deadlineAt !== null && $this->deadlineAt == $command->deadlineAt()) {
            return;
        }

        $this->recordThat(new DisputeDeadlineChanged(
            $this->deadlineAt,
            $command->deadlineAt(),
            $signalKey,
            $signal?->observedAt ?? $command->recordedAt(),
        ));
    }

    /**
     * Records one fee charged — or returned — in connection with this case.
     *
     * Deliberately not gated on the status, exactly as `PaymentIntentAggregate::recordFee()` is not:
     * the signal arrives out-of-band, fee data for a case already resolved is benign noise, and
     * refusing it would drop a fee the ledger needs because the case moved on before the settlement
     * file caught up.
     *
     * Two ways to be a repeat, and both record nothing. The same delivery applied twice is caught by
     * the key together with the fact it stated — and for a fee that fact is one word whatever the
     * fee, so a fee arriving under a delivery already applied is dropped rather than booked; see
     * {@see self::FACT_FEE} for what that costs and for the one observation that would change it. The
     * same fee stated by a *different* delivery — a poller re-reading a case, a webhook redelivering
     * one under a new id — is caught by the fee's own identity, which is all three of its parts: the
     * same $20 charged twice on two different days is two fees, and the same $20 at the same instant
     * is one fee told twice.
     */
    public function recordFee(RecordDisputeFeeCommand $command): void
    {
        $signal = $command->signal();

        if ($this->hasAlreadyApplied($signal->providerEventKey, self::FACT_FEE)) {
            return;
        }

        if (array_any($this->fees, fn($fee) => $fee->equals($command->fee()))) {
            return;
        }

        $this->recordThat(new DisputeFeeRecorded($command->fee(), $signal->providerEventKey, $signal->observedAt));
    }

    /**
     * Evidence staged at the provider and not yet filed — Stripe's `submit: false`.
     *
     * Reachable from `NONE` (first staging) and from `DRAFT` (the package grew), and from nowhere
     * else. `UPLOAD_PENDING` is the one state the submission table would let reach `DRAFT` that
     * staging may not reach it from, and that is a deliberate refusal rather than an oversight: the
     * package is with the provider, and only the provider's answer may bring it back — see
     * {@see self::rejectEvidenceUpload()}.
     */
    public function attachEvidence(AttachDisputeEvidenceCommand $command): void
    {
        $state = $this->submissionState;

        in_array($state, [SubmissionState::None, SubmissionState::Draft], true)
            || throw DisputeCannotChangeSubmissionState::notThisOperation(
                $state,
                'attachEvidence',
                [SubmissionState::None, SubmissionState::Draft],
            );

        $this->recordThat(new EvidenceAttached($state, SubmissionState::Draft, $command->types(), null, $command->at()));
    }

    /**
     * The package left our hands for the provider's upload endpoint — `DRAFT` to `UPLOAD_PENDING`.
     *
     * Not the same step as filing the response, and the difference is the point of the state: Nuvei
     * takes one base64 PDF and acknowledges it on a callback that has not arrived yet, so the file
     * is at Nuvei while the response has not been sent. Treating the upload as the filing would
     * record a submission nobody made, and the response window keeps running either way.
     */
    public function uploadEvidence(UploadDisputeEvidenceCommand $command): void
    {
        $this->submissionState->allows(SubmissionState::UploadPending)
            || throw DisputeCannotChangeSubmissionState::refused($this->submissionState, SubmissionState::UploadPending);

        $this->recordThat(new EvidenceAttached(
            $this->submissionState,
            SubmissionState::UploadPending,
            $command->types(),
            null,
            $command->at(),
        ));
    }

    /**
     * A re-query shows the file was never accepted — `UPLOAD_PENDING` back to `DRAFT`.
     *
     * The one backwards move on either axis, and it exists because the alternative is a case waiting
     * forever on an upload the provider does not have. Nothing was filed, the window is still
     * running, and the honest state is "we hold the evidence again".
     */
    public function rejectEvidenceUpload(RejectDisputeEvidenceUploadCommand $command): void
    {
        $signal = $command->signal();

        if ($this->hasAlreadyApplied($signal->providerEventKey, self::FACT_EVIDENCE_ATTACHED)) {
            return;
        }

        $this->submissionState === SubmissionState::UploadPending
            || throw DisputeCannotChangeSubmissionState::notThisOperation(
                $this->submissionState,
                'rejectEvidenceUpload',
                [SubmissionState::UploadPending],
            );

        $this->recordThat(new EvidenceAttached(
            SubmissionState::UploadPending,
            SubmissionState::Draft,
            $this->stagedEvidence,
            $signal->providerEventKey,
            $signal->observedAt,
        ));
    }

    /**
     * The response went out.
     *
     * Reachable from `NONE`, `DRAFT` and `UPLOAD_PENDING` — the last being the table's "upload
     * confirmed, submission sent", and `NONE` being the ConnexPay operator route, where a portal
     * handoff has no staging step and the operator's word is the only evidence anything was filed.
     *
     * It records that we sent it and not that anyone has it. A repeat is refused rather than
     * absorbed: `SUBMITTED` has no transition to itself, and a second filing of the same response
     * is not a duplicate delivery — it is a second submission, which is worth surfacing.
     */
    public function submitEvidence(SubmitDisputeEvidenceCommand $command): void
    {
        $this->submissionState->allows(SubmissionState::Submitted)
            || throw DisputeCannotChangeSubmissionState::refused($this->submissionState, SubmissionState::Submitted);

        $this->recordThat(new EvidenceSubmitted(
            $this->submissionState,
            SubmissionState::Submitted,
            $command->types(),
            null,
            $command->at(),
        ));
    }

    /**
     * The provider acknowledged something we sent. Which of the two things it was is in the event.
     *
     * **`confirmed: false` records nothing and moves nothing**, and this is the half of the method
     * that matters most. A provider that says it has not confirmed is reporting the absence of a new
     * fact, not a new one: ConnexPay's `HasResponse` flips back to `false` on a later poll and
     * Stripe never sends an acknowledgement at all. Demoting `SUBMITTED` on that reading would turn
     * a filed response back into an open question, and the cost of that is a case lost by default —
     * the response window keeps running while we believe we have not answered.
     *
     * Two acknowledgements are real. An acknowledgement while `UPLOAD_PENDING` is of the *file*: the
     * state does not move, and a timestamp records that the provider has the upload. An
     * acknowledgement while `SUBMITTED` is of the *response*, and is the only way `CONFIRMED` is
     * ever written — never synthesised, and never inferred from anything but this.
     */
    public function confirmEvidenceUpload(ConfirmEvidenceUploadCommand $command): void
    {
        $signal = $command->signal();

        if ($this->hasAlreadyApplied($signal->providerEventKey, self::FACT_EVIDENCE_UPLOAD_CONFIRMED)) {
            return;
        }

        if (! $command->confirmed()) {
            return;
        }

        if ($this->submissionState === SubmissionState::UploadPending) {
            $this->recordThat(new EvidenceUploadConfirmed(
                SubmissionState::UploadPending,
                SubmissionState::UploadPending,
                $signal->providerEventKey,
                $signal->observedAt,
            ));

            return;
        }

        $this->submissionState->allows(SubmissionState::Confirmed)
            || throw DisputeCannotChangeSubmissionState::refused($this->submissionState, SubmissionState::Confirmed);

        $this->recordThat(new EvidenceUploadConfirmed(
            $this->submissionState,
            SubmissionState::Confirmed,
            $signal->providerEventKey,
            $signal->observedAt,
        ));
    }

    /**
     * The guard every status move goes through, and the whole of the policy that is not the table.
     *
     * Returns whether a move should be recorded at all. Two answers of "no", and neither is an
     * error:
     *
     *  - **the case already holds the status.** A redelivery of a known fact, or a provider's
     *    statement repeated in a later payload.
     *  - **a `lost` after our own `ACCEPTED`** — {@see self::isDeliberateAcceptanceReplayed()}.
     *
     * Everything else is the table's: a move it does not list throws. `LOST` to `WON` is in it, and
     * is the only way out of `LOST` — an issuer crediting outside the normal cycle, which Stripe
     * reports as a late win, and money an aggregate that treated `LOST` as terminal would drop.
     */
    private function statusMayMoveTo(DisputeStatus $to): bool
    {
        if ($this->status === $to) {
            return false;
        }

        if ($this->isDeliberateAcceptanceReplayed($to)) {
            return false;
        }

        $this->status->allows($to) || throw DisputeCannotChangeStatus::refused($this->status, $to);

        return true;
    }

    /**
     * Whether this is the provider reporting `lost` on a case we deliberately conceded.
     *
     * Not a table rule, and it belongs here rather than in {@see DisputeStatus} because it is about
     * *why* we stopped fighting rather than about which states may follow which. F7's close tells
     * Stripe we have conceded; the provider has no way to record that, so the case comes back as
     * `lost` — the same word it would use if the issuer had decided against us. `ACCEPTED` is
     * terminal in the table, so without this the redelivery would throw; with it, the case keeps the
     * value that says we chose to stop, which is what A4 reads as loss recognition.
     *
     * Narrow on purpose. It suppresses exactly `ACCEPTED -> LOST` and nothing else: a `WON` arriving
     * after we conceded is not a replay, and it is refused by the table's own rule that `ACCEPTED`
     * is terminal — a case we chose to stop fighting is not one the issuer then decided for us, and
     * a value that flipped to `WON` would report money coming back that will not.
     */
    private function isDeliberateAcceptanceReplayed(DisputeStatus $to): bool
    {
        return $this->status === DisputeStatus::Accepted && $to === DisputeStatus::Lost;
    }

    /**
     * Whether this delivery's `$fact` has already been applied.
     *
     * Two comparisons, and both are needed.
     *
     * **The key identifies the delivery**, and equality is against the **most recent** one applied,
     * never membership of a set of every key ever seen. The plan is explicit about why, and the
     * reason is a real failure rather than a theoretical one: ConnexPay's key is a hash of the
     * case's current state, so a case that returns to an earlier combination of values — a
     * `HasResponse` that goes back to false, a resolution that is reopened — legitimately returns to
     * an earlier key. A set would suppress that transition as a duplicate, and it is exactly the
     * transition the mappings exist to report.
     *
     * **The fact says which of that delivery's statements this call is.** A delivery states more
     * than one thing, and the recorder applies them one call at a time: a Nuvei Chargeback DMN
     * carries the stage, the status and the due date under a single `DisputeEventId`, and a Stripe
     * `charge.dispute.updated` carries a status and often a new deadline. Compared against the key
     * alone, the first of those calls would record and each later one would look like the delivery
     * arriving again — so a case the network has just decided would keep the status it had, with
     * nothing recorded, nothing raised, and the loss visible only to whoever later noticed the case
     * never closed. {@see self::$appliedFactsOfLastDelivery} is where the facts are kept; the list
     * is emptied the moment a different delivery is applied, so a delivery that recurs after another
     * one still applies in full, as the key rule above requires.
     *
     * Only facts that produced an event are remembered, and both halves of that are deliberate: a
     * redelivery of a delivery that changed something finds its facts listed and records nothing,
     * while a delivery that changed nothing is remembered nowhere at all — because the values it
     * states are the values the aggregate already holds, so applying it again records nothing
     * either.
     */
    private function hasAlreadyApplied(string $signalKey, string $fact): bool
    {
        return $this->lastAppliedProviderEventKey === $signalKey
            && in_array($fact, $this->appliedFactsOfLastDelivery, true);
    }

    /**
     * Remembers one fact of a delivery, and only where there was one.
     *
     * Our own actions carry no key, and they must not clear the one a provider's delivery left —
     * nor the facts listed against it: the next redelivery of that delivery still has to be
     * recognised, whatever we did in between.
     *
     * A key we are not already applying starts its own list. That is what keeps this from being the
     * set of every fact ever applied, which the guard's docblock refuses for the same reason it
     * refuses a set of every key.
     */
    private function rememberSignal(?string $signalKey, string $fact): void
    {
        if ($signalKey === null) {
            return;
        }

        if ($this->lastAppliedProviderEventKey !== $signalKey) {
            $this->lastAppliedProviderEventKey = $signalKey;
            $this->appliedFactsOfLastDelivery = [];
        }

        $this->appliedFactsOfLastDelivery[] = $fact;
    }

    /**
     * @param list<EvidenceType> $existing
     * @param list<EvidenceType> $added
     *
     * @return list<EvidenceType>
     */
    private static function withAdditionalTypes(array $existing, array $added): array
    {
        $merged = $existing;

        foreach ($added as $type) {
            if (! in_array($type, $merged, true)) {
                $merged[] = $type;
            }
        }

        return $merged;
    }

    #[Override]
    protected function createSnapshotState(): array
    {
        return [
            'payment_intent_id' => $this->paymentIntentId->toString(),
            'stage' => $this->stage->value,
            'status' => $this->status->value,
            'submission_state' => $this->submissionState->value,
            'reason' => $this->reason->toPayload(),
            'disputed_amount' => $this->disputedAmount->getAmount(),
            'disputed_currency' => $this->disputedAmount->getCurrency()->getCode(),
            'fees' => array_map(static fn (DisputeFee $fee): array => $fee->toPayload(), $this->fees),
            'deadline_at' => $this->deadlineAt?->format(DateTimeInterface::ATOM),
            'staged_evidence' => array_map(static fn (EvidenceType $type): string => $type->value, $this->stagedEvidence),
            'submitted_evidence' => array_map(static fn (EvidenceType $type): string => $type->value, $this->submittedEvidence),
            // Carried into the snapshot, and this is the reason the snapshot exists at all beyond
            // speed: an aggregate restored from one that had forgotten it would accept the next
            // redelivery of the last delivery it applied as though it were new. The facts list
            // travels with the key and for the same reason — restored holding the key alone, the
            // aggregate would re-apply the second and later facts of that delivery.
            'last_applied_provider_event_key' => $this->lastAppliedProviderEventKey,
            'applied_facts_of_last_delivery' => $this->appliedFactsOfLastDelivery,
            'evidence_upload_confirmed_at' => $this->evidenceUploadConfirmedAt?->format(\DateTimeInterface::ATOM),
            'submission_confirmed_at' => $this->submissionConfirmedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    #[Override]
    protected static function reconstituteFromSnapshotState(AggregateRootId $id, $state): AggregateRootWithSnapshotting
    {
        // EventSauce's signature is the widest id type; a snapshot of this aggregate can only carry
        // its own.
        assert($id instanceof DisputeId);

        $self = new self($id);
        // Refused rather than defaulted, exactly as the sibling aggregates do it: a snapshot
        // missing one of these predates the field being required, and quietly substituting a value
        // would put a stage or a reason into the case that no provider ever reported.
        $self->paymentIntentId = isset($state['payment_intent_id'])
            ? PaymentIntentId::fromString($state['payment_intent_id'])
            : throw new RuntimeException("Dispute snapshot '{$id->toString()}' carries no payment intent id.");
        $self->stage = DisputeStage::from($state['stage'] ?? throw new RuntimeException("Dispute snapshot '{$id->toString()}' carries no stage."));
        $self->status = DisputeStatus::from($state['status'] ?? throw new RuntimeException("Dispute snapshot '{$id->toString()}' carries no status."));
        $self->submissionState = SubmissionState::from($state['submission_state'] ?? SubmissionState::None->value);
        $self->reason = DisputeReason::fromPayload($state['reason'] ?? throw new RuntimeException("Dispute snapshot '{$id->toString()}' carries no reason."));
        $currency = new Currency(
            $state['disputed_currency'] ?? throw new RuntimeException("Dispute snapshot '{$id->toString()}' carries no disputed currency."),
        );
        $self->disputedAmount = new Money($state['disputed_amount'], $currency);
        $self->fees = array_map(
            static fn (array $fee): DisputeFee => DisputeFee::fromPayload($fee),
            array_values($state['fees'] ?? []),
        );
        $self->deadlineAt = isset($state['deadline_at']) ? new DateTimeImmutable($state['deadline_at']) : null;
        $self->stagedEvidence = array_map(
            static fn (string $type): EvidenceType => EvidenceType::from($type),
            array_values($state['staged_evidence'] ?? []),
        );
        $self->submittedEvidence = array_map(
            static fn (string $type): EvidenceType => EvidenceType::from($type),
            array_values($state['submitted_evidence'] ?? []),
        );
        $self->lastAppliedProviderEventKey = $state['last_applied_provider_event_key'] ?? null;
        // Empty for a snapshot written before the facts were kept alongside the key, and that
        // fallback is safe rather than merely convenient: with no facts listed, a redelivery of the
        // remembered delivery is decided by the methods' own value guards, which is what decided it
        // before. See {@see self::hasAlreadyApplied()} for why the list exists at all.
        $self->appliedFactsOfLastDelivery = array_map(
            static fn (string $fact): string => $fact,
            array_values($state['applied_facts_of_last_delivery'] ?? []),
        );
        $self->evidenceUploadConfirmedAt = isset($state['evidence_upload_confirmed_at'])
            ? new DateTimeImmutable($state['evidence_upload_confirmed_at'])
            : null;
        $self->submissionConfirmedAt = isset($state['submission_confirmed_at'])
            ? new DateTimeImmutable($state['submission_confirmed_at'])
            : null;

        return $self;
    }

    protected function applyDisputeOpened(DisputeOpened $event): void
    {
        $this->paymentIntentId = $event->paymentIntentId;
        $this->stage = $event->stage;
        $this->status = $event->status;
        $this->reason = $event->reason;
        $this->disputedAmount = $event->disputedAmount;
        $this->deadlineAt = $event->deadlineAt;
        $this->rememberSignal($event->signalKey, self::FACT_OPENED);
        // The delivery that opened the case stated these three as well, under the same key, and
        // they are listed for the same reason every other fact is: a redelivery of it must not be
        // able to move a stage, a status or a deadline it already fixed. `DisputeOpened` carries all
        // three, so there is nothing here about a delivery that stayed silent — see
        // {@see self::open()}.
        $this->rememberSignal($event->signalKey, self::FACT_STAGE);
        $this->rememberSignal($event->signalKey, self::FACT_STATUS);
        $this->rememberSignal($event->signalKey, self::FACT_DEADLINE);
    }

    protected function applyDisputeStageChanged(DisputeStageChanged $event): void
    {
        $this->stage = $event->to;
        $this->rememberSignal($event->signalKey, self::FACT_STAGE);
    }

    protected function applyDisputeStatusChanged(DisputeStatusChanged $event): void
    {
        $this->status = $event->to;
        $this->rememberSignal($event->signalKey, self::FACT_STATUS);
    }

    protected function applyDisputeResolved(DisputeResolved $event): void
    {
        $this->status = $event->to;
        $this->rememberSignal($event->signalKey, self::FACT_STATUS);
    }

    protected function applyDisputeAccepted(DisputeAccepted $event): void
    {
        $this->status = DisputeStatus::Accepted;
        // Inert, and kept for the reason the constant exists: `accept()` is our own action and
        // carries no delivery, so there is no key to list this fact against. Were that ever to
        // change, this is the axis the event moved.
        $this->rememberSignal($event->signalKey, self::FACT_STATUS);
    }

    protected function applyDisputeDeadlineChanged(DisputeDeadlineChanged $event): void
    {
        $this->deadlineAt = $event->to;
        $this->rememberSignal($event->signalKey, self::FACT_DEADLINE);
    }

    protected function applyDisputeFeeRecorded(DisputeFeeRecorded $event): void
    {
        $this->fees[] = $event->fee;
        $this->rememberSignal($event->signalKey, self::FACT_FEE);
    }

    protected function applyEvidenceAttached(EvidenceAttached $event): void
    {
        $this->submissionState = $event->to;

        // Only while the package is ours. `UPLOAD_PENDING` means it is with the provider, and the
        // facts staged are whatever left with it — re-listing them from a rejection's own payload
        // would be reading our state back out of a delivery.
        if ($event->to === SubmissionState::Draft) {
            $this->stagedEvidence = self::withAdditionalTypes($this->stagedEvidence, $event->types);
        }

        $this->rememberSignal($event->signalKey, self::FACT_EVIDENCE_ATTACHED);
    }

    protected function applyEvidenceSubmitted(EvidenceSubmitted $event): void
    {
        $this->submissionState = $event->to;
        $this->submittedEvidence = $event->types;
        $this->rememberSignal($event->signalKey, self::FACT_EVIDENCE_SUBMITTED);
    }

    protected function applyEvidenceUploadConfirmed(EvidenceUploadConfirmed $event): void
    {
        $this->submissionState = $event->to;

        // Two acknowledgements with two different dates, and they must not share one field: the day
        // the provider accepted the file is not the day it accepted the response, and an operator
        // chasing a filing needs to know which has happened.
        if ($event->to === SubmissionState::UploadPending) {
            $this->evidenceUploadConfirmedAt = $event->occurredAt;
        } else {
            $this->submissionConfirmedAt = $event->occurredAt;
        }

        $this->rememberSignal($event->signalKey, self::FACT_EVIDENCE_UPLOAD_CONFIRMED);
    }
}
