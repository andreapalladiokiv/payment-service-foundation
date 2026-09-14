<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\Port\DisputeActionsPort;
use Techork\PaymentService\Domain\Dispute\Port\Request\AvailableActionsRequest;
use Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DashboardDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeActionSet;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeId;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceRequirements;
use Techork\PaymentService\Domain\Dispute\ValueObject\RespondDisputeAction;
use Techork\PaymentService\Tests\Support\ActionKindVisitor;
use Techork\PaymentService\Tests\Support\StubDisputeActionsPort;

/**
 * The action model: what is still open on a case, what each action carries, and what an empty set
 * means.
 *
 * The distinction under test is the one the plan exists around. An action set that is empty says
 * the case is not waiting on us; a provider that offers no API submission at all — ConnexPay, whose
 * CMS API is read-only — says the case *is* waiting on us and that a human answers it, which is a
 * non-empty set carrying a {@see DashboardDisputeAction} and a deep link. An implementation that
 * collapsed the two would report every ConnexPay case as closed, so both directions are asserted
 * here against the same fake adapter.
 *
 * Which actions a case admits is a different question, and it is not the port's: it is the case's
 * own rule, tested in `DisputeAvailableActionsTest`.
 */

function disputeActionRespondBy(string $at = '2026-09-26T00:00:00+00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at);
}

/**
 * One ConnexPay case, as their adapter has to answer it: the case is waiting on us, the file is
 * filed in the portal by a human, and there is no API submission that could carry it.
 *
 * The action carries the link and nothing else — no template, no deadline — so this is also the
 * fixture for "the panel exists and we hold an address for it".
 */
function connexPayCaseAction(string $reference): DashboardDisputeAction
{
    return new DashboardDisputeAction('https://cms.connexpay.com/Chargeback/Detail/'.rawurlencode($reference));
}

it('answers a ConnexPay-like case with an operator action rather than an empty set', function () {
    $reference = 'CB-2026-000123';

    // The provider offers no API submission, and this is still not "nothing to do": the case is
    // waiting on a human, and the action says where to find it.
    $connexPayLike = StubDisputeActionsPort::answering(
        $reference,
        DisputeActionSet::of([connexPayCaseAction($reference)]),
    );

    $actions = $connexPayLike->availableActions(AvailableActionsRequest::unattributable($reference));

    expect($actions->isEmpty())->toBeFalse()
        ->and($actions->actions())->toHaveCount(1)
        ->and($actions->get(DashboardDisputeAction::class)?->dashboardUrl)
        ->toBe('https://cms.connexpay.com/Chargeback/Detail/CB-2026-000123')
        // Nothing here is dispatchable by us, which is the fact an empty set would have stated
        // wrongly — the difference between "we have no call to make" and "nobody has anything to do".
        ->and($actions->has(RespondDisputeAction::class))->toBeFalse()
        ->and($actions->has(AcceptDisputeAction::class))->toBeFalse()
        // The port was actually asked, with the only name this case has: no aggregate, so no id of
        // ours and no row in the reference table either.
        ->and($connexPayLike->requests)->toHaveCount(1)
        ->and($connexPayLike->requests[0]->providerReference())->toBe($reference)
        ->and($connexPayLike->requests[0]->disputeId())->toBeNull();
});

it('states "not waiting on us" with the empty set, which is a different answer', function () {
    $reference = 'CB-2026-000123';

    // The same adapter on a case that has been resolved: no action is open, and that is a
    // statement about the case rather than about ConnexPay's API.
    $resolved = StubDisputeActionsPort::answering($reference, DisputeActionSet::none());

    $actions = $resolved->availableActions(AvailableActionsRequest::unattributable($reference));

    expect($actions->isEmpty())->toBeTrue()
        ->and($actions->actions())->toBe([])
        ->and($actions->has(RespondDisputeAction::class))->toBeFalse()
        ->and($actions->get(AcceptDisputeAction::class))->toBeNull()
        ->and($actions->get(DashboardDisputeAction::class))->toBeNull();
});

it('names a case of ours by our own id, leaving the adapter to resolve the reference', function () {
    $disputeId = DisputeId::generate();
    $fake = StubDisputeActionsPort::answering('dp_1Pabcdef', DisputeActionSet::none())
        ->withReference($disputeId, 'dp_1Pabcdef');

    // The part of "is this case waiting on us" that the provider cannot answer: a case we conceded
    // reads as `lost` at the provider, and an adapter that is not told our id cannot see that. The
    // provider's reference is deliberately not on the request — resolving it is the adapter's own
    // read of the reference table, and it is what the fake stands in for with `withReference()`.
    $fake->availableActions(AvailableActionsRequest::ofOurs($disputeId));

    expect($fake->requests[0]->disputeId()?->equals($disputeId))->toBeTrue()
        ->and($fake->requests[0]->providerReference())->toBeNull();
});

it('holds either our id or the provider reference, and refuses a blank reference', function () {
    $disputeId = DisputeId::generate();

    $ours = AvailableActionsRequest::ofOurs($disputeId);
    $theirs = AvailableActionsRequest::unattributable('CB-2026-000123');

    expect($ours->disputeId()?->equals($disputeId))->toBeTrue()
        // A case of ours carries no name of the provider's, and an unattributable one carries no
        // name of ours: the union is what keeps a half-named case unrepresentable.
        ->and($ours->providerReference())->toBeNull()
        ->and($theirs->disputeId())->toBeNull()
        ->and($theirs->providerReference())->toBe('CB-2026-000123')
        // Blank names no case at all, so the provider's answer to it would be read as this case's.
        ->and(fn () => AvailableActionsRequest::unattributable('   '))
        ->toThrow(InvalidArgumentException::class)
        // Whitespace-only is refused and whitespace-padded is not: a reference is a primary key at
        // the provider and is compared against what it holds, never corrected on the way in.
        ->and(AvailableActionsRequest::unattributable(' CB-2026-000123 ')->providerReference())
        ->toBe(' CB-2026-000123 ');
});

it('makes the retired invariants unexpressible rather than merely unchecked', function () {
    /** @var callable(class-string): list<string> $constructorArguments */
    $constructorArguments = static fn (string $class): array => array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
    );

    // An operator task with no link is buildable, which the old constructor refused: the link
    // travels when we hold one and the case is surfaced either way — the operator knows where the
    // provider's panel is, and the alternative was reporting the case as having nothing open on it.
    expect(new DashboardDisputeAction(null)->dashboardUrl)->toBeNull()
        ->and($constructorArguments(DashboardDisputeAction::class))->toBe(['dashboardUrl'])
        // A response carries the template and a deadline; an acceptance carries the ceiling and a
        // deadline. Neither can be handed a link, an acceptance cannot be handed requirements, and
        // there is no argument through which any of those combinations could be spelled — which is
        // what the old bag checked at runtime and what the constructors now say by shape.
        ->and($constructorArguments(RespondDisputeAction::class))->toBe(['requirements', 'respondBy'])
        ->and($constructorArguments(AcceptDisputeAction::class))->toBe(['disputedAmount', 'respondBy'])
        // The template is optional on a response and never on an acceptance: a case whose pair the
        // table does not carry still has to be surfaced with its deadline.
        ->and(new RespondDisputeAction(null, disputeActionRespondBy())->requirements)->toBeNull()
        ->and((new RespondDisputeAction(
            EvidenceRequirements::for(CardBrand::Visa, '13.1'),
            disputeActionRespondBy(),
        ))->requirements)->toBeInstanceOf(EvidenceRequirements::class);
});

it('recovers the concrete type through the visitor, one method per action', function () {
    $respond = new RespondDisputeAction(
        EvidenceRequirements::for(CardBrand::Visa, '13.1'),
        disputeActionRespondBy(),
    );
    $accept = new AcceptDisputeAction(new Money(25000, new Currency('USD')), disputeActionRespondBy());
    $dashboard = connexPayCaseAction('CB-2026-000123');

    $visitor = new ActionKindVisitor;

    expect($respond->accept($visitor))->toBe('respond')
        // The instance, not just the method: `accept()` on each concrete is one line, and the
        // argument's identity is the whole of what it promises.
        ->and($visitor->last)->toBe($respond)
        ->and($accept->accept($visitor))->toBe('accept')
        ->and($visitor->last)->toBe($accept)
        ->and($dashboard->accept($visitor))->toBe('dashboard')
        ->and($visitor->last)->toBe($dashboard);
});

it('refuses two actions of one type, which leave the caller no basis for choosing', function () {
    $respond = new RespondDisputeAction(null, disputeActionRespondBy());
    $accept = new AcceptDisputeAction(new Money(25000, new Currency('USD')), disputeActionRespondBy());
    $dashboard = connexPayCaseAction('CB-2026-000123');

    // Responding and conceding are both open at Stripe — one of each is the answer, not a
    // contradiction; the keying is by concrete class, which is the type there is.
    expect(DisputeActionSet::of([$respond, $accept])->actions())->toHaveCount(2)
        ->and(DisputeActionSet::of([$respond])->has(RespondDisputeAction::class))->toBeTrue()
        ->and(fn () => DisputeActionSet::of([$respond, $accept, $respond]))
        ->toThrow(InvalidArgumentException::class)
        // The one-per-type rule is not about the two API actions: a repeated link is the same
        // mapping bug, and the caller's choice is no better founded for being between two links.
        ->and(fn () => DisputeActionSet::of([$dashboard, $dashboard]))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to answer for a case it was never set up for', function () {
    $fake = StubDisputeActionsPort::answering('known_ref', DisputeActionSet::none());

    expect(fn () => $fake->availableActions(AvailableActionsRequest::unattributable('other_ref')))
        ->toThrow(LogicException::class);
});

it('refuses to resolve a case of ours that no reference row names', function () {
    // The other half of the fake's fidelity: an adapter cannot ask the provider about a case it
    // cannot name, so an id with no reference is a fixture hole rather than an empty answer.
    $fake = StubDisputeActionsPort::answering('known_ref', DisputeActionSet::none());

    expect(fn () => $fake->availableActions(AvailableActionsRequest::ofOurs(DisputeId::generate())))
        ->toThrow(LogicException::class);
});

it('is a port an implementation can be bound to without an API submission', function () {
    // The shape of the split: a provider with no API submission implements DisputeActionsPort and
    // nothing else, and the port says so without pretending the case is closed.
    $reference = 'CB-2026-000123';
    $port = StubDisputeActionsPort::answering($reference, DisputeActionSet::of([connexPayCaseAction($reference)]));

    expect($port)->toBeInstanceOf(DisputeActionsPort::class)
        ->and($port->availableActions(AvailableActionsRequest::unattributable($reference))->isEmpty())->toBeFalse();
});
