<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Contract;

use Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DashboardDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\RespondDisputeAction;

/**
 * What a caller does with an action, one method per concrete type.
 *
 * `TReturn` is the visitor's own type argument: a visitor that builds an operator task declares
 * `implements DisputeActionVisitor<OperatorTask>`, one that answers a query declares `<bool>`, and
 * {@see DisputeAction::accept()} returns that type at the call site rather than `mixed`.
 *
 * The interface ships with no implementation in this tree, and that is deliberate rather than an
 * omission: nothing in `src/` consumes an action set yet, and no gateway's capability is a strict
 * subset of what the set can carry — Stripe holds both the submission role and the concession role,
 * ConnexPay holds neither. When a provider does state per-case capabilities of its own (Nuvei 2.0's
 * `availableActions[]`), the implementation that consumes this belongs beside the adapters in
 * `src/Laravel/src/Port/` — the action types are Domain and the arch rule forbids naming them from
 * `src/<Provider>/src/**`.
 *
 * Adding a fourth action is breaking on purpose, exactly as
 * {@see \Techork\PaymentService\Common\Contract\ChallengeVisitor} is: a visitor that has not been
 * taught the new case would otherwise silently mis-handle an action it has never seen.
 *
 * @template TReturn
 */
interface DisputeActionVisitor
{
    /**
     * @return TReturn
     */
    public function visitRespond(RespondDisputeAction $action): mixed;

    /**
     * @return TReturn
     */
    public function visitAccept(AcceptDisputeAction $action): mixed;

    /**
     * @return TReturn
     */
    public function visitDashboard(DashboardDisputeAction $action): mixed;
}
