<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
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
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
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
use Techork\PaymentService\Domain\Dispute\SubmissionState;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeFee;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;
use Techork\PaymentService\Domain\Dispute\ValueObject\FeeType;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Tests\Support\DisputeAggregateTestCase;
use function EventSauce\EventSourcing\PestTooling\given;
use function EventSauce\EventSourcing\PestTooling\then;

uses(DisputeAggregateTestCase::class);

// ──────────────────────────────────────────────
//  Domain helpers
// ──────────────────────────────────────────────

function disputeAmount(int $minorUnits = 25000): Money
{
    return new Money($minorUnits, new Currency('USD'));
}

function disputeMoment(string $at = '2026-09-01T10:00:00+00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at);
}

/**
 * The signal key an event carries, read back off a {@see DisputeSignal} rather than restated.
 *
 * The key *is* the provider's own key for the delivery, and the gateway is not part of it: it is a
 * property of the stream — a case lives at one gateway account — rather than of the delivery, which
 * is why the recorder selects the aggregate with it instead of encoding it in the key.
 * {@see DisputeSignal} sets out the whole of that. Expectations still go through this helper so
 * that what an event records is coupled to what the signal exposes, not to a literal.
 */
function disputeSignalKey(string $providerEventKey): string
{
    return (new DisputeSignal($providerEventKey, disputeMoment()))->providerEventKey;
}

/**
 * One delivery of a provider fact.
 *
 * The default is Stripe's shape — a webhook `event.id` — because that is the key shape most of
 * these tests are about; the other two shapes (Nuvei's `DisputeEventId`, ConnexPay's snapshot hash)
 * are passed explicitly by the tests that exist to pin them down.
 */
function disputeSignal(
    string $providerEventKey = 'evt_1P00000000000000',
    string $observedAt = '2026-09-01T10:00:00+00:00',
): DisputeSignal {
    return new DisputeSignal($providerEventKey, disputeMoment($observedAt));
}

function disputeReason(CardBrand $brand = CardBrand::Visa, string $rawCode = '13.1'): DisputeReason
{
    return DisputeReason::fromProviderCode($brand, $rawCode);
}

function disputeFee(
    FeeType $type = FeeType::Chargeback,
    int $minorUnits = 1500,
    string $chargedAt = '2026-09-01T10:00:00+00:00',
): DisputeFee {
    return new DisputeFee($type, new Money($minorUnits, new Currency('USD')), disputeMoment($chargedAt));
}

function disputePaymentIntentId(): PaymentIntentId
{
    return PaymentIntentId::fromString('01961f5a-0000-7000-8000-0000000000aa');
}

function makeOpenDisputeCommand(
    DisputeId $id,
    DisputeStatus $status = DisputeStatus::NeedsResponse,
    DisputeStage $stage = DisputeStage::Chargeback,
    ?DateTimeImmutable $deadlineAt = null,
    ?DisputeReason $reason = null,
    ?Money $amount = null,
    ?string $providerCode = null,
    DisputeSignal|string $signal = 'evt_1P00000000000000',
): OpenDisputeCommand {
    return new class(
        $id,
        $status,
        $stage,
        $reason ?? disputeReason(),
        $amount ?? disputeAmount(),
        $deadlineAt,
        $providerCode,
        is_string($signal) ? disputeSignal($signal) : $signal,
    ) implements OpenDisputeCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly DisputeStatus $status,
            private readonly DisputeStage $stage,
            private readonly DisputeReason $reason,
            private readonly Money $amount,
            private readonly ?DateTimeImmutable $deadline,
            private readonly ?string $code,
            private readonly DisputeSignal $delivery,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function paymentIntentId(): PaymentIntentId
        {
            return disputePaymentIntentId();
        }

        public function stage(): DisputeStage
        {
            return $this->stage;
        }

        public function status(): DisputeStatus
        {
            return $this->status;
        }

        public function reason(): DisputeReason
        {
            return $this->reason;
        }

        public function disputedAmount(): Money
        {
            return $this->amount;
        }

        public function deadlineAt(): ?DateTimeImmutable
        {
            return $this->deadline;
        }

        public function providerCode(): ?string
        {
            return $this->code;
        }

        public function signal(): DisputeSignal
        {
            return $this->delivery;
        }
    };
}

/**
 * The same values the aggregate's own `open()` puts on `DisputeOpened`, so a test can seed a
 * case through `given()` without opening it — which is how every test past the opening ones
 * starts, since it is the appliers being exercised and not the factory.
 */
function disputeOpenedEvent(
    DisputeStatus $status = DisputeStatus::NeedsResponse,
    DisputeStage $stage = DisputeStage::Chargeback,
    ?DateTimeImmutable $deadlineAt = null,
    ?DisputeReason $reason = null,
    ?Money $amount = null,
    ?string $providerCode = null,
    string $signalKey = 'evt_opened00000000000',
    string $observedAt = '2026-08-31T09:00:00+00:00',
): DisputeOpened {
    return new DisputeOpened(
        disputePaymentIntentId(),
        $stage,
        $status,
        $reason ?? disputeReason(),
        $amount ?? disputeAmount(),
        $deadlineAt,
        $providerCode,
        $signalKey,
        disputeMoment($observedAt),
    );
}

function makeChangeStatusCommand(
    DisputeId $id,
    DisputeStatus $status,
    string $providerEventKey = 'evt_status000000000',
): ChangeDisputeStatusCommand {
    return new class($id, $status, disputeSignal($providerEventKey)) implements ChangeDisputeStatusCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly DisputeStatus $status,
            private readonly DisputeSignal $delivery,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function status(): DisputeStatus
        {
            return $this->status;
        }

        public function signal(): DisputeSignal
        {
            return $this->delivery;
        }
    };
}

function makeChangeStageCommand(
    DisputeId $id,
    DisputeStage $stage,
    ?string $providerCode = null,
    string $providerEventKey = 'evt_stage0000000000',
): ChangeDisputeStageCommand {
    return new class($id, $stage, $providerCode, disputeSignal($providerEventKey)) implements ChangeDisputeStageCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly DisputeStage $stage,
            private readonly ?string $code,
            private readonly DisputeSignal $delivery,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function stage(): DisputeStage
        {
            return $this->stage;
        }

        public function signal(): DisputeSignal
        {
            return $this->delivery;
        }

        public function providerCode(): ?string
        {
            return $this->code;
        }
    };
}

function makeAcceptDisputeCommand(DisputeId $id, string $at = '2026-09-05T12:00:00+00:00'): AcceptDisputeCommand
{
    return new class($id, disputeMoment($at)) implements AcceptDisputeCommand {
        public function __construct(private readonly DisputeId $id, private readonly DateTimeImmutable $moment) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function at(): DateTimeImmutable
        {
            return $this->moment;
        }
    };
}

function makeExpireDisputeCommand(DisputeId $id, string $at = '2026-09-09T00:00:00+00:00'): ExpireDisputeCommand
{
    return new class($id, disputeMoment($at)) implements ExpireDisputeCommand {
        public function __construct(private readonly DisputeId $id, private readonly DateTimeImmutable $moment) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function at(): DateTimeImmutable
        {
            return $this->moment;
        }
    };
}

function makeChangeDeadlineCommand(
    DisputeId $id,
    DateTimeImmutable $deadlineAt,
    ?DisputeSignal $signal = null,
    string $recordedAt = '2026-09-02T08:00:00+00:00',
): ChangeDisputeDeadlineCommand {
    return new class($id, $deadlineAt, $signal, disputeMoment($recordedAt)) implements ChangeDisputeDeadlineCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly DateTimeImmutable $deadline,
            private readonly ?DisputeSignal $delivery,
            private readonly DateTimeImmutable $moment,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function deadlineAt(): DateTimeImmutable
        {
            return $this->deadline;
        }

        public function signal(): ?DisputeSignal
        {
            return $this->delivery;
        }

        public function recordedAt(): DateTimeImmutable
        {
            return $this->moment;
        }
    };
}

function makeRecordDisputeFeeCommand(
    DisputeId $id,
    DisputeFee $fee,
    string $providerEventKey = 'evt_fee00000000000',
): RecordDisputeFeeCommand {
    return new class($id, $fee, disputeSignal($providerEventKey)) implements RecordDisputeFeeCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly DisputeFee $disputeFee,
            private readonly DisputeSignal $delivery,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function fee(): DisputeFee
        {
            return $this->disputeFee;
        }

        public function signal(): DisputeSignal
        {
            return $this->delivery;
        }
    };
}

/**
 * @param list<EvidenceType> $types
 */
function makeAttachEvidenceCommand(
    DisputeId $id,
    array $types = [EvidenceType::ProofOfDeliveryOrService],
    string $at = '2026-09-02T09:00:00+00:00',
): AttachDisputeEvidenceCommand {
    return new class($id, $types, disputeMoment($at)) implements AttachDisputeEvidenceCommand {
        /** @param list<EvidenceType> $types */
        public function __construct(
            private readonly DisputeId $id,
            private readonly array $types,
            private readonly DateTimeImmutable $moment,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        /** @return list<EvidenceType> */
        public function types(): array
        {
            return $this->types;
        }

        public function at(): DateTimeImmutable
        {
            return $this->moment;
        }
    };
}

/**
 * @param list<EvidenceType> $types
 */
function makeUploadEvidenceCommand(
    DisputeId $id,
    array $types = [EvidenceType::ProofOfDeliveryOrService],
    string $at = '2026-09-02T10:00:00+00:00',
): UploadDisputeEvidenceCommand {
    return new class($id, $types, disputeMoment($at)) implements UploadDisputeEvidenceCommand {
        /** @param list<EvidenceType> $types */
        public function __construct(
            private readonly DisputeId $id,
            private readonly array $types,
            private readonly DateTimeImmutable $moment,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        /** @return list<EvidenceType> */
        public function types(): array
        {
            return $this->types;
        }

        public function at(): DateTimeImmutable
        {
            return $this->moment;
        }
    };
}

function makeRejectEvidenceUploadCommand(
    DisputeId $id,
    string $providerEventKey = 'evt_reject000000000',
): RejectDisputeEvidenceUploadCommand {
    return new class($id, disputeSignal($providerEventKey)) implements RejectDisputeEvidenceUploadCommand {
        public function __construct(private readonly DisputeId $id, private readonly DisputeSignal $delivery) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function signal(): DisputeSignal
        {
            return $this->delivery;
        }
    };
}

/**
 * @param list<EvidenceType> $types
 */
function makeSubmitEvidenceCommand(
    DisputeId $id,
    array $types = [EvidenceType::ProofOfDeliveryOrService],
    string $at = '2026-09-02T11:00:00+00:00',
): SubmitDisputeEvidenceCommand {
    return new class($id, $types, disputeMoment($at)) implements SubmitDisputeEvidenceCommand {
        /** @param list<EvidenceType> $types */
        public function __construct(
            private readonly DisputeId $id,
            private readonly array $types,
            private readonly DateTimeImmutable $moment,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        /** @return list<EvidenceType> */
        public function types(): array
        {
            return $this->types;
        }

        public function at(): DateTimeImmutable
        {
            return $this->moment;
        }
    };
}

function makeConfirmEvidenceUploadCommand(
    DisputeId $id,
    bool $confirmed = true,
    string $providerEventKey = 'evt_confirm000000000',
): ConfirmEvidenceUploadCommand {
    return new class($id, $confirmed, disputeSignal($providerEventKey)) implements ConfirmEvidenceUploadCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly bool $confirmed,
            private readonly DisputeSignal $delivery,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function confirmed(): bool
        {
            return $this->confirmed;
        }

        public function signal(): DisputeSignal
        {
            return $this->delivery;
        }
    };
}

// ──────────────────────────────────────────────
//  Opening
// ──────────────────────────────────────────────

it('records DisputeOpened carrying the delivery that described the case', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    $aggregate = DisputeAggregate::open(makeOpenDisputeCommand(
        $id,
        providerCode: '1',
        deadlineAt: disputeMoment('2026-09-19T00:00:00+00:00'),
    ));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeOpened(
        disputePaymentIntentId(),
        DisputeStage::Chargeback,
        DisputeStatus::NeedsResponse,
        disputeReason(),
        disputeAmount(),
        disputeMoment('2026-09-19T00:00:00+00:00'),
        '1',
        disputeSignalKey('evt_1P00000000000000'),
        disputeMoment(),
    ));
});

it('binds the reason, amount and submission default onto the aggregate', function () {
    $aggregate = DisputeAggregate::open(makeOpenDisputeCommand(DisputeId::generate()));

    expect($aggregate->reason()->rawCode)->toBe('13.1')
        ->and($aggregate->reason()->cardBrand)->toBe(CardBrand::Visa)
        ->and($aggregate->disputedAmount())->toEqual(disputeAmount())
        // The submission axis opens at NONE without an event saying so: nothing of ours is at
        // the provider yet, and that is the absence of a fact rather than a fact.
        ->and($aggregate->submissionState())->toBe(SubmissionState::None)
        ->and($aggregate->fees())->toBe([]);
});

it('opens straight into a terminal state for a case that arrives already decided', function () {
    $aggregate = DisputeAggregate::open(makeOpenDisputeCommand(
        DisputeId::generate(),
        status: DisputeStatus::Lost,
    ));

    expect($aggregate->status())->toBe(DisputeStatus::Lost);
});

it('refuses to open a case as ACCEPTED because no provider reports our own concession', function () {
    DisputeAggregate::open(makeOpenDisputeCommand(DisputeId::generate(), status: DisputeStatus::Accepted));
})->throws(InvalidDispute::class);

it('refuses to open a case in ARBITRATION', function () {
    DisputeAggregate::open(makeOpenDisputeCommand(DisputeId::generate(), stage: DisputeStage::Arbitration));
})->throws(InvalidDispute::class);

it('refuses a disputed amount that is not positive', function () {
    DisputeAggregate::open(makeOpenDisputeCommand(
        DisputeId::generate(),
        amount: new Money(0, new Currency('USD')),
    ));
})->throws(InvalidDispute::class);

/**
 * The opening stage is the opening event's own stage, not something a projector has to infer from a
 * stage change: a case that never moved releases one `DisputeOpened` and no `DisputeStageChanged`,
 * and the provider's cycle code it arrived with rides on that event.
 *
 * `then()` asserts the whole released set, so the absence of a stage change is part of what this
 * pins — and it is what makes the stream self-sufficient. A projection that read the opening stage
 * off stage changes alone would report an empty history for every case that never escalated.
 */
it('carries the opening stage and cycle code on the opening event, not on a stage change', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    $aggregate = DisputeAggregate::open(makeOpenDisputeCommand(
        $id,
        stage: DisputeStage::Inquiry,
        providerCode: '17',
    ));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeOpened(
        disputePaymentIntentId(),
        DisputeStage::Inquiry,
        DisputeStatus::NeedsResponse,
        disputeReason(),
        disputeAmount(),
        null,
        '17',
        disputeSignalKey('evt_1P00000000000000'),
        disputeMoment(),
    ));
});

// ──────────────────────────────────────────────
//  Status — the table
// ──────────────────────────────────────────────

it('records DisputeStatusChanged on NEEDS_RESPONSE -> UNDER_REVIEW', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::UnderReview));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeStatusChanged(
        DisputeStatus::NeedsResponse,
        DisputeStatus::UnderReview,
        disputeSignalKey('evt_status000000000'),
        disputeMoment(),
    ));
});

it('records DisputeResolved on NEEDS_RESPONSE -> WON', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Won));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeResolved(
        DisputeStatus::NeedsResponse,
        DisputeStatus::Won,
        disputeSignalKey('evt_status000000000'),
        disputeMoment(),
    ));
});

it('records DisputeResolved on NEEDS_RESPONSE -> LOST', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Lost));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeResolved(
        DisputeStatus::NeedsResponse,
        DisputeStatus::Lost,
        disputeSignalKey('evt_status000000000'),
        disputeMoment(),
    ));
});

it('allows LOST -> WON without exception, which is the late win', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::Lost));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Won));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeResolved(
        DisputeStatus::Lost,
        DisputeStatus::Won,
        disputeSignalKey('evt_status000000000'),
        disputeMoment(),
    ));
});

it('lands the case on WON, the one way out of LOST', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::Lost));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Won));

    expect($aggregate->status())->toBe(DisputeStatus::Won)
        ->and($aggregate->status()->isResolution())->toBeTrue();
});

it('records DisputeStatusChanged on NEEDS_RESPONSE -> CLOSED', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Closed));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeStatusChanged(
        DisputeStatus::NeedsResponse,
        DisputeStatus::Closed,
        disputeSignalKey('evt_status000000000'),
        disputeMoment(),
    ));
});

it('records DisputeStatusChanged on UNDER_REVIEW -> CLOSED', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::UnderReview));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Closed));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeStatusChanged(
        DisputeStatus::UnderReview,
        DisputeStatus::Closed,
        disputeSignalKey('evt_status000000000'),
        disputeMoment(),
    ));
});

it('moves UNDER_REVIEW -> EXPIRED nowhere, because the table does not list it', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::UnderReview));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->expire(makeExpireDisputeCommand($id));
})->throws(DisputeCannotChangeStatus::class);

it('refuses UNDER_REVIEW -> NEEDS_RESPONSE, which is the table read backwards', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::UnderReview));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::NeedsResponse));
})->throws(DisputeCannotChangeStatus::class);

it('refuses every move out of a terminal status', function (DisputeStatus $terminal, DisputeStatus $target) {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: $terminal));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, $target));
})->throws(DisputeCannotChangeStatus::class)->with([
    'WON -> LOST' => [DisputeStatus::Won, DisputeStatus::Lost],
    'WON -> UNDER_REVIEW' => [DisputeStatus::Won, DisputeStatus::UnderReview],
    'WON -> EXPIRED' => [DisputeStatus::Won, DisputeStatus::Expired],
    // ACCEPTED -> LOST is deliberately absent from this dataset: it is absorbed rather than
    // refused, and it has its own test below.
    'ACCEPTED -> WON' => [DisputeStatus::Accepted, DisputeStatus::Won],
    'ACCEPTED -> CLOSED' => [DisputeStatus::Accepted, DisputeStatus::Closed],
    'EXPIRED -> CLOSED' => [DisputeStatus::Expired, DisputeStatus::Closed],
    'CLOSED -> EXPIRED' => [DisputeStatus::Closed, DisputeStatus::Expired],
    'CLOSED -> WON' => [DisputeStatus::Closed, DisputeStatus::Won],
]);

it('treats a status the case already holds as a redelivery and records nothing', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::UnderReview));

    $aggregate = $this->retrieveAggregateRoot($id);
    // A *different* key, so this is a second delivery of a known fact rather than the same
    // delivery twice — the distinction the two guards exist to make.
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::UnderReview, 'evt_second000000000'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();
});

// ──────────────────────────────────────────────
//  ACCEPTED — our own concession, and the lost that follows it
// ──────────────────────────────────────────────

it('records DisputeAccepted on accept, and the aggregate reads ACCEPTED', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->accept(makeAcceptDisputeCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeAccepted(DisputeStatus::NeedsResponse, null, disputeMoment('2026-09-05T12:00:00+00:00')));

    expect($aggregate->status())->toBe(DisputeStatus::Accepted);
});

it('records DisputeAccepted from UNDER_REVIEW as well', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::UnderReview));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->accept(makeAcceptDisputeCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeAccepted(DisputeStatus::UnderReview, null, disputeMoment('2026-09-05T12:00:00+00:00')));
});

it('is idempotent across a repeated accept, because the state says it already happened', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::Accepted));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->accept(makeAcceptDisputeCommand($id));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();
});

it('does not let the provider report our own concession back to us as a status', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Accepted));
})->throws(DisputeCannotChangeStatus::class);

it('leaves ACCEPTED standing when the provider reports the lost that follows our close', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // The case we conceded deliberately, and then the `charge.dispute.closed` carrying `lost`
    // that Stripe sends because its API has no way to record why we stopped fighting.
    given(
        disputeOpenedEvent(),
        new DisputeAccepted(DisputeStatus::NeedsResponse, null, disputeMoment('2026-09-05T12:00:00+00:00')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Lost, 'evt_closed000000000'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->status())->toBe(DisputeStatus::Accepted);
});

it('is not demoted by the same lost arriving on a different delivery', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new DisputeAccepted(DisputeStatus::NeedsResponse, null, disputeMoment('2026-09-05T12:00:00+00:00')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Lost, 'evt_closed_a'));
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Lost, 'evt_closed_b'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->status())->toBe(DisputeStatus::Accepted);
});

it('refuses a late win that arrives after our own ACCEPTED, rather than absorbing it', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new DisputeAccepted(DisputeStatus::NeedsResponse, null, disputeMoment('2026-09-05T12:00:00+00:00')),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    // ACCEPTED is terminal in the table, so this is refused rather than recorded: the replay rule
    // is narrow on purpose and suppresses exactly the `lost` the provider cannot explain.
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Won, 'evt_latewin000000000'));
})->throws(DisputeCannotChangeStatus::class);

it('records a late win on a case that was lost and never conceded', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // The narrowness of the replay rule read the other way: LOST -> WON is the transition the
    // plan insists is real, and nothing here may suppress it.
    given(disputeOpenedEvent(status: DisputeStatus::Lost));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Won, 'evt_latewin000000000'));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeResolved(
        DisputeStatus::Lost,
        DisputeStatus::Won,
        disputeSignalKey('evt_latewin000000000'),
        disputeMoment(),
    ));
});

// ──────────────────────────────────────────────
//  Expiry — the application's clock, never ours
// ──────────────────────────────────────────────

it('records DisputeStatusChanged on the explicit expiry command, carrying the moment it was given', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->expire(makeExpireDisputeCommand($id, '2026-09-09T00:00:00+00:00'));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeStatusChanged(
        DisputeStatus::NeedsResponse,
        DisputeStatus::Expired,
        null,
        disputeMoment('2026-09-09T00:00:00+00:00'),
    ));
});

it('does not expire a case on its own when the deadline has passed', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(
        deadlineAt: disputeMoment('2020-01-01T00:00:00+00:00'),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);

    // The deadline is long past and the case is still `NEEDS_RESPONSE`, because nothing in the
    // aggregate consults a clock: expiry is a provider signal or the application's own command.
    // An aggregate with a timer would have moved this case on the strength of a date alone.
    expect($aggregate->deadlineAt() <= disputeMoment())->toBeTrue()
        ->and($aggregate->status())->toBe(DisputeStatus::NeedsResponse);
});

it('treats an expiry of an already expired case as a repeat', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::Expired));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->expire(makeExpireDisputeCommand($id));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();
});

it('records EXPIRED arriving as a provider signal, which is the other route into it', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Expired));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeStatusChanged(
        DisputeStatus::NeedsResponse,
        DisputeStatus::Expired,
        disputeSignalKey('evt_status000000000'),
        disputeMoment(),
    ));
});

// ──────────────────────────────────────────────
//  Stage
// ──────────────────────────────────────────────

it('records DisputeStageChanged carrying the provider cycle code', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(stage: DisputeStage::Inquiry, providerCode: '17'));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStage(makeChangeStageCommand($id, DisputeStage::Chargeback, '1'));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeStageChanged(
        DisputeStage::Inquiry,
        DisputeStage::Chargeback,
        '1',
        disputeSignalKey('evt_stage0000000000'),
        disputeMoment(),
    ));
});

/**
 * ConnexPay folds two and three of its `CaseType` values onto one stage, so a case can sit in
 * `CHARGEBACK` twice in one family and the two visits are told apart by the provider's cycle code
 * and nothing else. Both codes must therefore be in the stream — one on each event — because they
 * are what a projector reading it reconstructs the history from.
 */
it('releases the opening cycle code and the changed one, so the two visits stay apart', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    $aggregate = DisputeAggregate::open(makeOpenDisputeCommand($id, providerCode: '1'));
    $aggregate->changeStage(makeChangeStageCommand($id, DisputeStage::PreArbitration, '9', 'evt_stage2'));
    $this->persistAggregateRoot($aggregate);

    then(
        new DisputeOpened(
            disputePaymentIntentId(),
                DisputeStage::Chargeback,
            DisputeStatus::NeedsResponse,
            disputeReason(),
            disputeAmount(),
            null,
            '1',
            disputeSignalKey('evt_1P00000000000000'),
            disputeMoment(),
        ),
        new DisputeStageChanged(
            DisputeStage::Chargeback,
            DisputeStage::PreArbitration,
            '9',
            disputeSignalKey('evt_stage2'),
            disputeMoment(),
        ),
    );
});

it('records nothing when a signal restates the stage the case already holds', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(providerCode: '1'));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStage(makeChangeStageCommand($id, DisputeStage::Chargeback, '2', 'evt_stage2'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();
});

it('refuses a provider signal that would land the case in ARBITRATION', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(stage: DisputeStage::PreArbitration));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStage(makeChangeStageCommand($id, DisputeStage::Arbitration, '24'));
})->throws(InvalidDispute::class);

// ──────────────────────────────────────────────
//  Idempotency — the key, and the three shapes of providerEventKey
// ──────────────────────────────────────────────

it('applies a Stripe event id once and records nothing for its redelivery', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // The delivery that opened this case was a Stripe webhook, and its `event.id` is the key the
    // case remembers. The redelivery below names a status the table *would* allow, so what stops it
    // is the key and not the state — which is the property under test.
    given(disputeOpenedEvent(signalKey: disputeSignalKey('evt_1P00000000000001')));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::UnderReview, 'evt_1P00000000000001'));
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Won, 'evt_1P00000000000001'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->status())->toBe(DisputeStatus::NeedsResponse)
        ->and($aggregate->lastAppliedProviderEventKey())->toBe(disputeSignalKey('evt_1P00000000000001'));
});

it('applies a Nuvei DisputeEventId once and records nothing for its redelivery', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(signalKey: disputeSignalKey('8844211')));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::UnderReview, '8844211'));
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Won, '8844211'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->status())->toBe(DisputeStatus::NeedsResponse)
        ->and($aggregate->lastAppliedProviderEventKey())->toBe(disputeSignalKey('8844211'));
});

it('applies a ConnexPay snapshot hash once and records nothing for an unchanged case', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // A real key of the third shape, as the ConnexPay poller produces it: the case as the CMS
    // currently states it, hashed and `v1:`-prefixed. Written as a literal on purpose — nothing
    // about it looks like an event id, which is exactly why the aggregate may not interpret the
    // value, and the hashing itself is the ConnexPay package's, pinned by its own tests.
    $hash = 'v1:f9a29361e2609830fcfa2b5e44a47c5afc18491f2c5a95578f91edd9f242b51d';

    given(disputeOpenedEvent(signalKey: disputeSignalKey($hash, 'connexpay-main')));

    $aggregate = $this->retrieveAggregateRoot($id);
    // The case polled again, unchanged: the same snapshot hashes to the same key, so nothing is
    // reported — and a `NEEDS_RESPONSE -> WON` on that same poll is suppressed with it.
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::UnderReview, $hash, 'connexpay-main'));
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Won, $hash, 'connexpay-main'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->status())->toBe(DisputeStatus::NeedsResponse)
        ->and($aggregate->lastAppliedProviderEventKey())->toBe(disputeSignalKey($hash, 'connexpay-main'));
});

it('records two events for two different deliveries of the same kind', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::UnderReview, 'evt_first'));
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Closed, 'evt_second'));
    $this->persistAggregateRoot($aggregate);

    // Two deliveries, two facts, two events — the guard is equality against the previous key and
    // not a "have I ever seen this case change" latch.
    then(
        new DisputeStatusChanged(
            DisputeStatus::NeedsResponse,
            DisputeStatus::UnderReview,
            disputeSignalKey('evt_first'),
            disputeMoment(),
        ),
        new DisputeStatusChanged(
            DisputeStatus::UnderReview,
            DisputeStatus::Closed,
            disputeSignalKey('evt_second'),
            disputeMoment(),
        ),
    );
});

it('re-applies a key the case has returned to, because only the most recent one is remembered', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // A ConnexPay case whose stage goes chargeback -> pre-arbitration -> chargeback again returns to
    // the hash of the first reading. A set of every key ever seen would drop that third delivery as
    // a duplicate, and the demotion it reports is exactly what the mapping exists to report.
    $first = 'v1:'.str_repeat('a', 64);
    $second = 'v1:'.str_repeat('b', 64);

    given(disputeOpenedEvent(providerCode: '1'));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStage(makeChangeStageCommand($id, DisputeStage::PreArbitration, '9', $first));
    $aggregate->changeStage(makeChangeStageCommand($id, DisputeStage::Chargeback, '1', $second));
    $aggregate->changeStage(makeChangeStageCommand($id, DisputeStage::Inquiry, '30', $first));
    $this->persistAggregateRoot($aggregate);

    then(
        new DisputeStageChanged(
            DisputeStage::Chargeback,
            DisputeStage::PreArbitration,
            '9',
            disputeSignalKey($first),
            disputeMoment(),
        ),
        new DisputeStageChanged(
            DisputeStage::PreArbitration,
            DisputeStage::Chargeback,
            '1',
            disputeSignalKey($second),
            disputeMoment(),
        ),
        new DisputeStageChanged(
            DisputeStage::Chargeback,
            DisputeStage::Inquiry,
            '30',
            disputeSignalKey($first),
            disputeMoment(),
        ),
    );

    expect($aggregate->stage())->toBe(DisputeStage::Inquiry)
        ->and($aggregate->lastAppliedProviderEventKey())->toBe(disputeSignalKey($first));
});

it('applies every fact one delivery states, not only the first of them', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // One delivery, three facts, one key — the shape every provider that describes a *case* rather
    // than one change to it hands the recorder. A Nuvei Chargeback DMN carries the stage, the
    // status and the due date under a single `DisputeEventId`; a ConnexPay poll carries the whole
    // case under one state hash; a Stripe `charge.dispute.updated` carries a status and often a new
    // deadline. Against the key alone the first of these calls records and the rest look like the
    // delivery arriving again — so a case the network has just decided keeps the status it had, and
    // nothing records that it moved, which is the whole of what a dispute tracker is for.
    $key = 'evt_triple_fact';
    $dueAt = disputeMoment('2026-09-20T00:00:00+00:00');

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStage(makeChangeStageCommand($id, DisputeStage::PreArbitration, '9', $key));
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::UnderReview, $key));
    $aggregate->changeDeadline(makeChangeDeadlineCommand($id, $dueAt, disputeSignal($key)));
    $this->persistAggregateRoot($aggregate);

    then(
        new DisputeStageChanged(
            DisputeStage::Chargeback,
            DisputeStage::PreArbitration,
            '9',
            disputeSignalKey($key),
            disputeMoment(),
        ),
        new DisputeStatusChanged(
            DisputeStatus::NeedsResponse,
            DisputeStatus::UnderReview,
            disputeSignalKey($key),
            disputeMoment(),
        ),
        new DisputeDeadlineChanged(null, $dueAt, disputeSignalKey($key), disputeMoment()),
    );

    expect($aggregate->stage())->toBe(DisputeStage::PreArbitration)
        ->and($aggregate->status())->toBe(DisputeStatus::UnderReview)
        ->and($aggregate->deadlineAt())->toEqual($dueAt)
        // All three listed against the one key, and never a fourth: the list is this delivery's
        // facts, so it cannot become a history that suppresses a later delivery's return to them.
        ->and($aggregate->appliedFactsOfLastDelivery())->toBe(['stage', 'status', 'deadline'])
        ->and($aggregate->lastAppliedProviderEventKey())->toBe(disputeSignalKey($key));
});

it('drops a fact of a delivery that was already applied, whatever the delivery states', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // The other side of the same rule, and the reason the fact is listed at all: a delivery is
    // applied once. The stage below is one the case does not hold and no value guard would refuse,
    // and it is dropped because the *delivery* that is said to carry it was already applied —
    // which is what keeps a redelivery from walking a case backwards through its stages.
    $key = 'evt_applied_already';

    given(
        disputeOpenedEvent(),
        new DisputeStageChanged(
            DisputeStage::Chargeback,
            DisputeStage::PreArbitration,
            '9',
            disputeSignalKey($key),
            disputeMoment(),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStage(makeChangeStageCommand($id, DisputeStage::Inquiry, '30', $key));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->stage())->toBe(DisputeStage::PreArbitration);
});

it('carries the facts of the last delivery through a snapshot', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // Why the facts travel with the key rather than beside it: restored holding the key alone, an
    // aggregate would re-apply the second and later facts of that delivery. This case is where the
    // value guards do not save it — a confirming delivery applied once leaves the submission
    // `CONFIRMED`, and `CONFIRMED` has no transition to itself, so the redelivery would not be
    // absorbed but *thrown*, and the router would retry it until it failed visibly. The pair is
    // asserted together because either half alone answers the wrong question.
    $key = 'evt_confirmed_file';

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::Draft,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment(),
        ),
        new EvidenceSubmitted(
            SubmissionState::Draft,
            SubmissionState::Submitted,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment(),
        ),
        new EvidenceUploadConfirmed(
            SubmissionState::Submitted,
            SubmissionState::Confirmed,
            disputeSignalKey($key),
            disputeMoment(),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);

    $state = (fn () => $this->createSnapshotState())->call($aggregate);
    $restored = (fn () => DisputeAggregate::reconstituteFromSnapshotState($id, $state))->call($aggregate);

    expect($restored->lastAppliedProviderEventKey())->toBe(disputeSignalKey($key))
        ->and($restored->appliedFactsOfLastDelivery())->toBe(['evidence_upload_confirmed'])
        ->and($restored->submissionState())->toBe(SubmissionState::Confirmed);

    // The property, not the field: the restored aggregate takes the redelivery as the repeat it is,
    // records nothing, and does not throw.
    $restored->confirmEvidenceUpload(makeConfirmEvidenceUploadCommand($id, true, $key));

    expect($restored->releaseEvents())->toBe([]);
});

it('keeps the remembered key across our own actions, which carry no delivery', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::UnderReview, 'evt_1P00000000000001'));
    // Our own concession between the delivery and its redelivery: it must not clear the key, or
    // the redelivery would be applied a second time.
    $aggregate->accept(makeAcceptDisputeCommand($id));

    expect($aggregate->lastAppliedProviderEventKey())->toBe(disputeSignalKey('evt_1P00000000000001'));
});

it('refuses a provider event key that is blank', function () {
    new DisputeSignal('   ', disputeMoment());
})->throws(InvalidDispute::class);

// ──────────────────────────────────────────────
//  Fees
// ──────────────────────────────────────────────

it('records DisputeFeeRecorded', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->recordFee(makeRecordDisputeFeeCommand($id, disputeFee()));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeFeeRecorded(disputeFee(), disputeSignalKey('evt_fee00000000000'), disputeMoment()));
});

it('keeps Stripe\'s two fees distinct rather than netting them into one number', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->recordFee(makeRecordDisputeFeeCommand($id, disputeFee(FeeType::Chargeback, 1500)));
    $aggregate->recordFee(makeRecordDisputeFeeCommand(
        $id,
        disputeFee(FeeType::Countered, 1500, '2026-09-05T09:00:00+00:00'),
        'evt_fee2',
    ));

    expect($aggregate->fees())->toHaveCount(2)
        ->and($aggregate->fees()[0]->type)->toBe(FeeType::Chargeback)
        ->and($aggregate->fees()[1]->type)->toBe(FeeType::Countered);
});

it('records nothing when the same fee is stated by a different delivery', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // The fee is already on the case, recorded under one delivery's key. A poller re-reading the
    // case arrives with a *new* key and the same fee, so the key guard cannot be what catches it:
    // identity is the triple (type, amount, chargedAt), and that is what makes it a repeat.
    given(
        disputeOpenedEvent(),
        new DisputeFeeRecorded(disputeFee(), disputeSignalKey('evt_fee_a'), disputeMoment()),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->recordFee(makeRecordDisputeFeeCommand($id, disputeFee(), 'evt_fee_b'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->fees())->toHaveCount(1);
});

it('records the same amount charged at a different moment as two fees', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->recordFee(makeRecordDisputeFeeCommand($id, disputeFee(FeeType::Chargeback, 1500)));
    $aggregate->recordFee(makeRecordDisputeFeeCommand(
        $id,
        disputeFee(FeeType::Chargeback, 1500, '2026-09-08T09:00:00+00:00'),
        'evt_fee2',
    ));
    $this->persistAggregateRoot($aggregate);

    then(
        new DisputeFeeRecorded(disputeFee(FeeType::Chargeback, 1500), disputeSignalKey('evt_fee00000000000'), disputeMoment()),
        new DisputeFeeRecorded(
            disputeFee(FeeType::Chargeback, 1500, '2026-09-08T09:00:00+00:00'),
            disputeSignalKey('evt_fee2'),
            disputeMoment(),
        ),
    );

    expect($aggregate->fees())->toHaveCount(2);
});

it('records nothing when a fee redelivery reuses the same key', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // Here it is the key and not the identity that catches the repeat: the second telling states a
    // *different* fee under the delivery that was already applied, and applying it would double the
    // case's fees on the strength of a payload the provider sent once.
    given(
        disputeOpenedEvent(),
        new DisputeFeeRecorded(disputeFee(), disputeSignalKey('evt_fee_a'), disputeMoment()),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->recordFee(makeRecordDisputeFeeCommand(
        $id,
        disputeFee(FeeType::Countered, 1500, '2026-09-05T09:00:00+00:00'),
        'evt_fee_a',
    ));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->fees())->toHaveCount(1);
});

it('refuses a fee that is not a positive amount', function () {
    disputeFee(FeeType::Chargeback, 0);
})->throws(InvalidDispute::class);

it('refuses a zero fee, because a fee that was not charged is not a fee', function () {
    // ConnexPay's reversal *denial* carries $0 where its reversal acceptance carries $20, and the
    // zero is the absence of a fee rather than a fee of zero: nothing was debited, so there is
    // nothing for A4 to sign and nothing to record. A zero-amount entry would also be
    // indistinguishable from a mis-parsed payload.
    disputeFee(FeeType::ReversalAcceptance, 0);
})->throws(InvalidDispute::class);

it('records a fee on a case that has already resolved', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(status: DisputeStatus::Won));

    $aggregate = $this->retrieveAggregateRoot($id);
    // Fees arrive out of band and after the fact; refusing one because the case moved on would
    // drop a fee the ledger needs.
    $aggregate->recordFee(makeRecordDisputeFeeCommand($id, disputeFee(FeeType::Countered, 1500)));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeFeeRecorded(
        disputeFee(FeeType::Countered, 1500),
        disputeSignalKey('evt_fee00000000000'),
        disputeMoment(),
    ));

    expect($aggregate->fees())->toHaveCount(1);
});

// ──────────────────────────────────────────────
//  Deadline
// ──────────────────────────────────────────────

it('records DisputeDeadlineChanged from a provider signal', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeDeadline(makeChangeDeadlineCommand(
        $id,
        disputeMoment('2026-09-19T00:00:00+00:00'),
        disputeSignal('evt_due00000000000'),
    ));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeDeadlineChanged(
        null,
        disputeMoment('2026-09-19T00:00:00+00:00'),
        disputeSignalKey('evt_due00000000000'),
        disputeMoment(),
    ));
});

/**
 * The one command on this aggregate whose delivery is optional: a date we worked out has nothing to
 * point at, and the absence is what the recorded event carries instead of a key. Nothing on the date
 * itself says where it came from — the stream says it, by the key being there or not.
 *
 * `recordedAt` is used as the event's moment exactly here, because a provider's own statement would
 * have carried when it was observed and passing both would invite them to disagree.
 */
it('records a date we worked out with no delivery key and our own moment', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeDeadline(makeChangeDeadlineCommand(
        $id,
        disputeMoment('2026-09-12T00:00:00+00:00'),
    ));
    $this->persistAggregateRoot($aggregate);

    then(new DisputeDeadlineChanged(
        null,
        disputeMoment('2026-09-12T00:00:00+00:00'),
        null,
        disputeMoment('2026-09-02T08:00:00+00:00'),
    ));

    expect($aggregate->deadlineAt())->toEqual(disputeMoment('2026-09-12T00:00:00+00:00'));
});

it('records nothing when the same deadline is stated again', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(
        deadlineAt: disputeMoment('2026-09-19T00:00:00+00:00'),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeDeadline(makeChangeDeadlineCommand(
        $id,
        disputeMoment('2026-09-19T00:00:00+00:00'),
        disputeSignal('evt_due00000000000'),
    ));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();
});

it('records a deadline that moved even when it moved by a day', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent(
        deadlineAt: disputeMoment('2026-09-19T00:00:00+00:00'),
    ));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeDeadline(makeChangeDeadlineCommand(
        $id,
        disputeMoment('2026-09-20T00:00:00+00:00'),
        disputeSignal('evt_due00000000000'),
    ));
    $this->persistAggregateRoot($aggregate);

    // A day is the smallest move a date can make, and the guard is equality against the whole
    // value rather than a tolerance: a deadline that shifts by one day is a different deadline.
    then(new DisputeDeadlineChanged(
        disputeMoment('2026-09-19T00:00:00+00:00'),
        disputeMoment('2026-09-20T00:00:00+00:00'),
        disputeSignalKey('evt_due00000000000'),
        disputeMoment(),
    ));

    expect($aggregate->deadlineAt()?->format('Y-m-d'))->toBe('2026-09-20');
});

// ──────────────────────────────────────────────
//  The submission axis
// ──────────────────────────────────────────────

it('records EvidenceAttached on NONE -> DRAFT, which is Stripe staging with submit: false', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->attachEvidence(makeAttachEvidenceCommand($id, [EvidenceType::ProofOfDeliveryOrService]));
    $this->persistAggregateRoot($aggregate);

    then(new EvidenceAttached(
        SubmissionState::None,
        SubmissionState::Draft,
        [EvidenceType::ProofOfDeliveryOrService],
        null,
        disputeMoment('2026-09-02T09:00:00+00:00'),
    ));

    expect($aggregate->stagedEvidence())->toBe([EvidenceType::ProofOfDeliveryOrService]);
});

it('grows the staged set when evidence is attached again while it is still ours', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->attachEvidence(makeAttachEvidenceCommand($id, [EvidenceType::ProofOfDeliveryOrService]));
    $aggregate->attachEvidence(makeAttachEvidenceCommand(
        $id,
        [EvidenceType::CustomerCorrespondence],
        '2026-09-02T09:30:00+00:00',
    ));

    expect($aggregate->stagedEvidence())->toBe([
        EvidenceType::ProofOfDeliveryOrService,
        EvidenceType::CustomerCorrespondence,
    ]);
});

it('records EvidenceAttached on DRAFT -> UPLOAD_PENDING, which is the file leaving our hands', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::Draft,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T09:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->uploadEvidence(makeUploadEvidenceCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(new EvidenceAttached(
        SubmissionState::Draft,
        SubmissionState::UploadPending,
        [EvidenceType::ProofOfDeliveryOrService],
        null,
        disputeMoment('2026-09-02T10:00:00+00:00'),
    ));

    expect($aggregate->submissionState())->toBe(SubmissionState::UploadPending);
});

it('records EvidenceAttached on UPLOAD_PENDING -> DRAFT when a re-query shows the file was refused', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::Draft,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T09:00:00+00:00'),
        ),
        new EvidenceAttached(
            SubmissionState::Draft,
            SubmissionState::UploadPending,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T10:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->rejectEvidenceUpload(makeRejectEvidenceUploadCommand($id, 'evt_image0000000000'));
    $this->persistAggregateRoot($aggregate);

    then(new EvidenceAttached(
        SubmissionState::UploadPending,
        SubmissionState::Draft,
        [EvidenceType::ProofOfDeliveryOrService],
        disputeSignalKey('evt_image0000000000'),
        disputeMoment(),
    ));

    expect($aggregate->submissionState())->toBe(SubmissionState::Draft);
});

it('records EvidenceSubmitted on DRAFT -> SUBMITTED', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::Draft,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T09:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->submitEvidence(makeSubmitEvidenceCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(new EvidenceSubmitted(
        SubmissionState::Draft,
        SubmissionState::Submitted,
        [EvidenceType::ProofOfDeliveryOrService],
        null,
        disputeMoment('2026-09-02T11:00:00+00:00'),
    ));

    expect($aggregate->submittedEvidence())->toBe([EvidenceType::ProofOfDeliveryOrService]);
});

it('submits straight from NONE, which is the ConnexPay operator route', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    // A portal handoff has no staging step: the operator's word is the only evidence anything
    // was filed, and there is no DRAFT to move through first.
    $aggregate->submitEvidence(makeSubmitEvidenceCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(new EvidenceSubmitted(
        SubmissionState::None,
        SubmissionState::Submitted,
        [EvidenceType::ProofOfDeliveryOrService],
        null,
        disputeMoment('2026-09-02T11:00:00+00:00'),
    ));
});

it('records EvidenceSubmitted on UPLOAD_PENDING -> SUBMITTED once the upload is accepted', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::UploadPending,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T10:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->submitEvidence(makeSubmitEvidenceCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(new EvidenceSubmitted(
        SubmissionState::UploadPending,
        SubmissionState::Submitted,
        [EvidenceType::ProofOfDeliveryOrService],
        null,
        disputeMoment('2026-09-02T11:00:00+00:00'),
    ));
});

it('records EvidenceUploadConfirmed of the file without moving UPLOAD_PENDING', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::UploadPending,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T10:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmEvidenceUpload(makeConfirmEvidenceUploadCommand($id, true, 'evt_image_ok'));
    $this->persistAggregateRoot($aggregate);

    then(new EvidenceUploadConfirmed(
        SubmissionState::UploadPending,
        SubmissionState::UploadPending,
        disputeSignalKey('evt_image_ok'),
        disputeMoment(),
    ));

    expect($aggregate->submissionState())->toBe(SubmissionState::UploadPending)
        ->and($aggregate->evidenceUploadConfirmedAt())->toEqual(disputeMoment())
        ->and($aggregate->submissionConfirmedAt())->toBeNull();
});

it('records EvidenceUploadConfirmed on SUBMITTED -> CONFIRMED and stamps the submission moment', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceSubmitted(
            SubmissionState::None,
            SubmissionState::Submitted,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T11:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmEvidenceUpload(makeConfirmEvidenceUploadCommand($id, true, 'evt_hadresponse'));
    $this->persistAggregateRoot($aggregate);

    then(new EvidenceUploadConfirmed(
        SubmissionState::Submitted,
        SubmissionState::Confirmed,
        disputeSignalKey('evt_hadresponse'),
        disputeMoment(),
    ));

    expect($aggregate->submissionState())->toBe(SubmissionState::Confirmed)
        ->and($aggregate->submissionConfirmedAt())->toEqual(disputeMoment());
});

it('is idempotent across a redelivered acknowledgement', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceUploadConfirmed(
            SubmissionState::Submitted,
            SubmissionState::Confirmed,
            disputeSignalKey('evt_hadresponse'),
            disputeMoment(),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmEvidenceUpload(makeConfirmEvidenceUploadCommand($id, true, 'evt_hadresponse'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();
});

it('leaves SUBMITTED standing when a later reading says the response is not confirmed', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceSubmitted(
            SubmissionState::None,
            SubmissionState::Submitted,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T11:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    // `HasResponse: false` on a subsequent poll. Demoting here would turn a filed response back
    // into an open question, and the response window keeps running either way.
    $aggregate->confirmEvidenceUpload(makeConfirmEvidenceUploadCommand($id, false, 'evt_nothadresponse'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->submissionState())->toBe(SubmissionState::Submitted);
});

it('leaves CONFIRMED standing when a later reading says the response is not confirmed', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceUploadConfirmed(
            SubmissionState::Submitted,
            SubmissionState::Confirmed,
            disputeSignalKey('evt_hadresponse'),
            disputeMoment(),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmEvidenceUpload(makeConfirmEvidenceUploadCommand($id, false, 'evt_nothadresponse'));
    $this->persistAggregateRoot($aggregate);

    $this->nothingShouldHaveHappened();

    expect($aggregate->submissionState())->toBe(SubmissionState::Confirmed);
});

it('stops at SUBMITTED for a provider that never acknowledges, rather than synthesising CONFIRMED', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceSubmitted(
            SubmissionState::None,
            SubmissionState::Submitted,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T11:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);

    expect($aggregate->submissionState())->toBe(SubmissionState::Submitted)
        ->and($aggregate->submissionState()->isTerminal())->toBeFalse()
        ->and($aggregate->submissionConfirmedAt())->toBeNull()
        // CONFIRMED is the only terminal value on this axis, and it is written by nothing but a
        // provider's own acknowledgement.
        ->and(SubmissionState::Confirmed->isTerminal())->toBeTrue();
});

it('refuses a repeat submission rather than absorbing it', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceSubmitted(
            SubmissionState::None,
            SubmissionState::Submitted,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T11:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->submitEvidence(makeSubmitEvidenceCommand($id));
})->throws(DisputeCannotChangeSubmissionState::class);

it('refuses to stage evidence while the package is with the provider', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::UploadPending,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T10:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->attachEvidence(makeAttachEvidenceCommand($id, [EvidenceType::CustomerCorrespondence]));
})->throws(DisputeCannotChangeSubmissionState::class);

it('refuses an acknowledgement of a response that was never sent', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->confirmEvidenceUpload(makeConfirmEvidenceUploadCommand($id, true, 'evt_hadresponse'));
})->throws(DisputeCannotChangeSubmissionState::class);

it('refuses to upload a package that was never staged', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->uploadEvidence(makeUploadEvidenceCommand($id));
})->throws(DisputeCannotChangeSubmissionState::class);

it('refuses a rejection of an upload that is not outstanding', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::Draft,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T09:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->rejectEvidenceUpload(makeRejectEvidenceUploadCommand($id));
})->throws(DisputeCannotChangeSubmissionState::class);

// ──────────────────────────────────────────────
//  The two axes are independent
// ──────────────────────────────────────────────

it('moves the submission axis without moving the status', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(disputeOpenedEvent());

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->attachEvidence(makeAttachEvidenceCommand($id));
    $aggregate->uploadEvidence(makeUploadEvidenceCommand($id));
    $aggregate->submitEvidence(makeSubmitEvidenceCommand($id));

    expect($aggregate->submissionState())->toBe(SubmissionState::Submitted)
        ->and($aggregate->status())->toBe(DisputeStatus::NeedsResponse);
});

it('moves the status without moving the submission axis', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::Draft,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T09:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::UnderReview));

    expect($aggregate->status())->toBe(DisputeStatus::UnderReview)
        ->and($aggregate->submissionState())->toBe(SubmissionState::Draft);
});

it('keeps UPLOAD_PENDING while the provider still reports NEEDS_RESPONSE', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    // Nuvei's own combination: the file is with Nuvei and its Image callback has not arrived,
    // while the case itself is still waiting on our response. Folding the two axes together
    // would have made this state unrepresentable.
    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::UploadPending,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T10:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);

    expect($aggregate->submissionState())->toBe(SubmissionState::UploadPending)
        ->and($aggregate->status())->toBe(DisputeStatus::NeedsResponse);
});

it('resolves a case whose response has not been confirmed, because the two axes end apart', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceSubmitted(
            SubmissionState::None,
            SubmissionState::Submitted,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T11:00:00+00:00'),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeStatus(makeChangeStatusCommand($id, DisputeStatus::Won));

    expect($aggregate->status())->toBe(DisputeStatus::Won)
        ->and($aggregate->submissionState())->toBe(SubmissionState::Submitted);
});

// ──────────────────────────────────────────────
//  Snapshotting
// ──────────────────────────────────────────────

it('snapshot roundtrip preserves the state and the last applied provider key', function () {
    /** @var DisputeId $id */
    $id = $this->aggregateRootId();

    given(
        disputeOpenedEvent(),
        new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::Draft,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment('2026-09-02T09:00:00+00:00'),
        ),
        new DisputeStatusChanged(
            DisputeStatus::NeedsResponse,
            DisputeStatus::UnderReview,
            disputeSignalKey('evt_snapshot00000000'),
            disputeMoment(),
        ),
        new DisputeFeeRecorded(disputeFee(), disputeSignalKey('evt_fee00000000000'), disputeMoment()),
        new DisputeDeadlineChanged(
            null,
            disputeMoment('2026-09-19T00:00:00+00:00'),
            disputeSignalKey('evt_due00000000000'),
            disputeMoment(),
        ),
        new DisputeStageChanged(
            DisputeStage::Chargeback,
            DisputeStage::PreArbitration,
            '9',
            disputeSignalKey('evt_stage0000000000'),
            disputeMoment(),
        ),
    );

    $aggregate = $this->retrieveAggregateRoot($id);

    $state = (fn () => $this->createSnapshotState())->call($aggregate);
    $restored = (fn () => DisputeAggregate::reconstituteFromSnapshotState($id, $state))->call($aggregate);

    expect($restored->status())->toBe(DisputeStatus::UnderReview)
        ->and($restored->stage())->toBe(DisputeStage::PreArbitration)
        ->and($restored->submissionState())->toBe(SubmissionState::Draft)
        ->and($restored->stagedEvidence())->toBe([EvidenceType::ProofOfDeliveryOrService])
        ->and($restored->fees())->toEqual([disputeFee()])
        ->and($restored->reason())->toEqual(disputeReason())
        ->and($restored->disputedAmount())->toEqual(disputeAmount())
        // The load-bearing one: an aggregate restored without the key would accept the next
        // redelivery of the last delivery it applied as though it were new.
        ->and($restored->lastAppliedProviderEventKey())->toBe(disputeSignalKey('evt_stage0000000000'));

    then();
});

it('round-trips every event payload through toPayload and fromPayload', function (string $class) {
    $event = match ($class) {
        DisputeOpened::class => disputeOpenedEvent(),
        DisputeStageChanged::class => new DisputeStageChanged(
            DisputeStage::Chargeback,
            DisputeStage::PreArbitration,
            '9',
            disputeSignalKey('evt_stage0000000000'),
            disputeMoment(),
        ),
        DisputeStatusChanged::class => new DisputeStatusChanged(
            DisputeStatus::NeedsResponse,
            DisputeStatus::UnderReview,
            disputeSignalKey('evt_status000000000'),
            disputeMoment(),
        ),
        DisputeResolved::class => new DisputeResolved(
            DisputeStatus::Lost,
            DisputeStatus::Won,
            disputeSignalKey('evt_latewin000000000'),
            disputeMoment(),
        ),
        DisputeAccepted::class => new DisputeAccepted(DisputeStatus::NeedsResponse, null, disputeMoment()),
        DisputeDeadlineChanged::class => new DisputeDeadlineChanged(
            null,
            disputeMoment('2026-09-12T00:00:00+00:00'),
            null,
            disputeMoment(),
        ),
        DisputeFeeRecorded::class => new DisputeFeeRecorded(
            disputeFee(),
            disputeSignalKey('evt_fee00000000000'),
            disputeMoment(),
        ),
        EvidenceAttached::class => new EvidenceAttached(
            SubmissionState::None,
            SubmissionState::Draft,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment(),
        ),
        EvidenceSubmitted::class => new EvidenceSubmitted(
            SubmissionState::None,
            SubmissionState::Submitted,
            [EvidenceType::ProofOfDeliveryOrService],
            null,
            disputeMoment(),
        ),
        EvidenceUploadConfirmed::class => new EvidenceUploadConfirmed(
            SubmissionState::Submitted,
            SubmissionState::Confirmed,
            disputeSignalKey('evt_hadresponse'),
            disputeMoment(),
        ),
    };

    expect($event::fromPayload($event->toPayload()))->toEqual($event);
})->with([
    DisputeOpened::class,
    DisputeStageChanged::class,
    DisputeStatusChanged::class,
    DisputeResolved::class,
    DisputeAccepted::class,
    DisputeDeadlineChanged::class,
    DisputeFeeRecorded::class,
    EvidenceAttached::class,
    EvidenceSubmitted::class,
    EvidenceUploadConfirmed::class,
]);
