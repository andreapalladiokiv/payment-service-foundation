<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\Command\AcceptDisputeCommand;
use Techork\PaymentService\Domain\Dispute\Command\OpenDisputeCommand;
use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\DisputeStage;
use Techork\PaymentService\Domain\Dispute\DisputeStatus;
use Techork\PaymentService\Domain\Dispute\Exception\InvalidDispute;
use Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DashboardDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeReason;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeSignal;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceRequirements;
use Techork\PaymentService\Domain\Dispute\ValueObject\RespondDisputeAction;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * The rule: which actions a case admits, from the case's own stage, status and deadline.
 *
 * This is the ceiling every adapter subtracts from, and the only place in this tree that says what
 * a dispute is still waiting for. It is a pure query — no port, no gateway call — so the whole table
 * is asserted on aggregates built directly, without a provider anywhere near it.
 *
 * Two statements of the rule are worth reading before the table:
 *
 *  - **the predicate is positive on `NEEDS_RESPONSE`**, never "not terminal". `Lost` is not terminal
 *    in this domain (`DisputeStatus::isTerminal()` is false for it), so a rule written by exclusion
 *    would offer a concession on a case that has already been decided.
 *  - **the concession is withheld on `INQUIRY` and nowhere else.** The clause is `!== INQUIRY` and not
 *    `=== CHARGEBACK`, because a case escalated to `PRE_ARBITRATION` keeps it — and because the
 *    predicate must not depend on which stages a given provider happens to reach.
 */

function availableActionsCase(
    DisputeStage $stage = DisputeStage::Chargeback,
    DisputeStatus $status = DisputeStatus::NeedsResponse,
    ?DateTimeImmutable $deadlineAt = null,
    ?Money $amount = null,
    ?DisputeReason $reason = null,
): DisputeAggregate {
    return DisputeAggregate::open(new class(
        DisputeId::generate(),
        $status,
        $stage,
        $reason ?? DisputeReason::fromProviderCode(CardBrand::Visa, '13.1'),
        $amount ?? new Money(25000, new Currency('USD')),
        $deadlineAt,
    ) implements OpenDisputeCommand {
        public function __construct(
            private readonly DisputeId $id,
            private readonly DisputeStatus $status,
            private readonly DisputeStage $stage,
            private readonly DisputeReason $reason,
            private readonly Money $amount,
            private readonly ?DateTimeImmutable $deadline,
        ) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function paymentIntentId(): PaymentIntentId
        {
            return PaymentIntentId::fromString('01961f5a-0000-7000-8000-0000000000bb');
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
            return null;
        }

        public function signal(): DisputeSignal
        {
            return new DisputeSignal('evt_1P00000000000000', new DateTimeImmutable('2026-09-01T10:00:00+00:00'));
        }
    });
}

function availableActionsRespondBy(string $at = '2026-09-26T00:00:00+00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at);
}

it('opens a chargeback to both a response and a concession', function () {
    $deadline = availableActionsRespondBy('2026-09-20T00:00:00+00:00');

    $actions = availableActionsCase(deadlineAt: $deadline)->availableDisputeActions();

    $respond = $actions->get(RespondDisputeAction::class);
    $accept = $actions->get(AcceptDisputeAction::class);

    expect($actions->actions())->toHaveCount(2)
        ->and($actions->isEmpty())->toBeFalse()
        ->and($respond?->respondBy)->toEqual($deadline)
        ->and($accept?->respondBy)->toEqual($deadline)
        // The action carries the ceiling — what is at stake — and not the concession, which is
        // chosen at the call.
        ->and($accept?->disputedAmount)->toEqual(new Money(25000, new Currency('USD')))
        // No pair was stated by the read, so there is no template. This is not "nothing is needed":
        // the case is still surfaced, with its deadline and its action.
        ->and($respond?->requirements)->toBeNull();
});

it('withholds the concession on an inquiry and nowhere else', function () {
    $deadline = availableActionsRespondBy();

    // The one stage where giving up the case is not what the case is for yet: at an inquiry the
    // network is asking a question, and conceding is not one of the answers it is asking for.
    $inquiry = availableActionsCase(DisputeStage::Inquiry, deadlineAt: $deadline)->availableDisputeActions();

    expect($inquiry->has(RespondDisputeAction::class))->toBeTrue()
        ->and($inquiry->has(AcceptDisputeAction::class))->toBeFalse()
        ->and($inquiry->get(RespondDisputeAction::class)?->respondBy)->toEqual($deadline);

    // Escalation keeps it, which is why the clause is `!== INQUIRY` rather than `=== CHARGEBACK`:
    // Stripe never reports a pre-arbitration stage (its own status mapping says so), so a rule
    // written the other way round would silently drop the concession for one provider and not
    // another.
    $escalated = availableActionsCase(DisputeStage::PreArbitration, deadlineAt: $deadline)->availableDisputeActions();

    expect($escalated->has(AcceptDisputeAction::class))->toBeTrue()
        ->and($escalated->has(RespondDisputeAction::class))->toBeTrue();

    // Arbitration is admitted by the predicate **and unreachable**, which is a statement about the
    // rule and about the domain at once: `ARBITRATION` cannot even be opened — the aggregate refuses
    // it because no adapter in this tree maps a signal to that phase — so the clause is never
    // exercised. It is written as `!== INQUIRY` anyway rather than enumerated over today's reachable
    // stages, so the day one adapter does reach the phase, the concession is already there instead of
    // being a second place to go and change.
    expect(fn () => availableActionsCase(DisputeStage::Arbitration, deadlineAt: $deadline))
        ->toThrow(InvalidDispute::class);
});

it('leaves nothing open on a case we conceded ourselves', function () {
    // The one state a provider never reports: its API knows only that the merchant stopped fighting,
    // so it says `lost`, and this aggregate is the only place that can tell a concession of ours from
    // a decision against us. It is reached through `accept()`, not through a signal — a delivery
    // naming `ACCEPTED` is refused as a mapping bug.
    $aggregate = availableActionsCase(deadlineAt: availableActionsRespondBy());
    $aggregate->accept(new class($aggregate->aggregateRootId()) implements AcceptDisputeCommand {
        public function __construct(private readonly DisputeId $id) {}

        public function disputeId(): DisputeId
        {
            return $this->id;
        }

        public function at(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-09-05T10:00:00+00:00');
        }
    });

    $actions = $aggregate->availableDisputeActions();

    expect($aggregate->status())->toBe(DisputeStatus::Accepted)
        ->and($actions->isEmpty())->toBeTrue()
        ->and($actions->has(RespondDisputeAction::class))->toBeFalse()
        ->and($actions->has(AcceptDisputeAction::class))->toBeFalse();
});

it('leaves a case nothing to do once it is no longer waiting on us', function (DisputeStatus $status) {
    // Every status but one, and the one that is missing is the point: the predicate is positive on
    // NEEDS_RESPONSE rather than "not terminal", which matters because `Lost` is not terminal in
    // this domain — it still allows a move to `Won` — and a rule written by exclusion would offer a
    // concession on a case the network has already decided against us.
    $actions = availableActionsCase(status: $status, deadlineAt: availableActionsRespondBy())
        ->availableDisputeActions();

    expect($actions->isEmpty())->toBeTrue()
        ->and($actions->has(RespondDisputeAction::class))->toBeFalse()
        ->and($actions->has(AcceptDisputeAction::class))->toBeFalse()
        ->and(DisputeStatus::NeedsResponse)->not->toBe($status);
})->with([
    'under review' => [DisputeStatus::UnderReview],
    'won' => [DisputeStatus::Won],
    'lost' => [DisputeStatus::Lost],
    'expired' => [DisputeStatus::Expired],
    'closed' => [DisputeStatus::Closed],
    // `ACCEPTED` is missing from this list on purpose and is not a gap: no provider reports it, so
    // it cannot be opened from a signal — the aggregate refuses that as a mapping bug — and the
    // concession that produces it is asserted on its own below.
]);

it('states "not constructible" rather than "not waiting on us" when no deadline is known', function () {
    // The one reading of an empty set that is not about the case: both actions carry a non-nullable
    // deadline, so a case awaiting a response with no window has no task that can be built at all.
    // Two guards keep it rare — a provider's read refuses to exist reporting `awaitingResponse`
    // without a deadline, and only a backlog import opens a case without one.
    $noDeadline = availableActionsCase(deadlineAt: null)->availableDisputeActions();

    expect($noDeadline->isEmpty())->toBeTrue()
        ->and($noDeadline->actions())->toBe([]);
});

it('takes the deadline from the live read, and falls back to our own record', function () {
    $recorded = availableActionsRespondBy('2026-09-20T00:00:00+00:00');
    $live = availableActionsRespondBy('2026-09-26T00:00:00+00:00');

    // The read is newer than the last delivery we applied, and the window between deliveries is
    // when a case is lost: a stated deadline is the one the operator is given.
    $withArgument = availableActionsCase(deadlineAt: $recorded)->availableDisputeActions(respondBy: $live);

    expect($withArgument->get(RespondDisputeAction::class)?->respondBy)->toEqual($live)
        ->and($withArgument->get(AcceptDisputeAction::class)?->respondBy)->toEqual($live);

    // Null is not "no deadline" here — it is "the read stated none", and our own record is what we
    // have. That is the asymmetry with the evidence pair below, and it is principled: the deadline
    // is kept current by `changeDeadline()` while the reason is frozen at the opening delivery.
    $withoutArgument = availableActionsCase(deadlineAt: $recorded)->availableDisputeActions();

    expect($withoutArgument->get(RespondDisputeAction::class)?->respondBy)->toEqual($recorded);

    // A case our record holds no deadline for, asked with a live one, is answered rather than
    // reported as having nothing open.
    $recordedNothing = availableActionsCase(deadlineAt: null)->availableDisputeActions(respondBy: $live);

    expect($recordedNothing->get(RespondDisputeAction::class)?->respondBy)->toEqual($live);
});

it('builds the template from the pair the read states, and never from our own record', function () {
    $deadline = availableActionsRespondBy();

    $stated = availableActionsCase(deadlineAt: $deadline)->availableDisputeActions(
        cardBrand: CardBrand::Visa,
        reasonCode: '13.1',
    );

    expect($stated->get(RespondDisputeAction::class)?->requirements)
        ->toBeInstanceOf(EvidenceRequirements::class)
        ->toEqual(EvidenceRequirements::for(CardBrand::Visa, '13.1'));

    // The case was opened with Visa `13.1` recorded, and asked with no pair — and the answer is no
    // template. The record is deliberately not a fallback: `reason()` is written once by `open()`
    // and never moved by `changeStatus`/`changeStage`, so reading it here would answer for the
    // opening delivery's pair while the live case may be arguing a different one.
    $notStated = availableActionsCase(deadlineAt: $deadline)->availableDisputeActions();

    expect($notStated->get(RespondDisputeAction::class)?->requirements)->toBeNull();

    // Half a pair is not a pair: the table is keyed by both, and a brand without a code addresses
    // no row.
    $halfStated = availableActionsCase(deadlineAt: $deadline)->availableDisputeActions(cardBrand: CardBrand::Visa);

    expect($halfStated->get(RespondDisputeAction::class)?->requirements)->toBeNull();

    // And a pair the table does not carry is null rather than a near neighbour's rules — a code the
    // table has not written down is a gap to surface, not a near-match to accept.
    $unmapped = availableActionsCase(deadlineAt: $deadline)->availableDisputeActions(
        cardBrand: CardBrand::Visa,
        reasonCode: 'NOT-A-CODE',
    );

    expect($unmapped->get(RespondDisputeAction::class)?->requirements)->toBeNull();
});

it('admits no action a case does not name, whatever the read states', function () {
    // The ceiling is the case's, and the arguments the live read supplies are facts *about* the
    // case, never permissions. A pair of a brand and a code on a settled case changes nothing, and
    // neither does a fresh deadline.
    $settled = availableActionsCase(
        status: DisputeStatus::Won,
        deadlineAt: availableActionsRespondBy('2026-09-20T00:00:00+00:00'),
    )->availableDisputeActions(
        respondBy: availableActionsRespondBy(),
        cardBrand: CardBrand::Visa,
        reasonCode: '13.1',
    );

    expect($settled->isEmpty())->toBeTrue()
        ->and($settled->has(DashboardDisputeAction::class))->toBeFalse();
});
