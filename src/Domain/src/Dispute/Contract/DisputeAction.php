<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Contract;

/**
 * One thing still open on a case: what has to happen, by when, and — where the network is owed an
 * argument — what it expects to see.
 *
 * ## One class per action, and the concrete class *is* the type
 *
 * There is deliberately no `kind()` and no `ActionType` beside this interface. A discriminator
 * would be a field every caller has to branch on, and every branch would be an `if`/`switch` over a
 * value the type system already knows — `kind()` побудит нас делать if/switch, and that is what this
 * shape exists to avoid. Asking "which of these am I holding" is answered by *being* the class, and
 * a caller that needs the concrete type to render or to dispatch recovers it through
 * {@see DisputeActionVisitor} rather than at the call site.
 *
 * What that buys beyond taste is that the three facts the old property bag checked at runtime are
 * now **unexpressible in the wrong shape**: a response carries its evidence requirements and a
 * deadline, an acceptance carries the disputed sum and a deadline, a dashboard action carries a
 * link and nothing else. There is no constructor argument through which an acceptance could be
 * given requirements or a response a portal link, so no guard is needed and no combination has to be
 * remembered.
 *
 * ## Which actions a case offers is the domain's rule, and a provider may only narrow it
 *
 * {@see \Techork\PaymentService\Domain\Dispute\DisputeAggregate::availableDisputeActions()} states
 * what a case's own stage, status and deadline permit. A provider adapter may **subtract** from that
 * answer — the live read is the one fact the aggregate cannot see, and the window between deliveries
 * is when a case is lost — but it may never add an action the case itself does not admit. The
 * asymmetry is the point: an action offered on a case that is not waiting on us is an irreversible
 * call on a case somebody else already decided.
 *
 * An empty set is therefore a statement about the case and not about a provider's API, which is the
 * distinction {@see \Techork\PaymentService\Domain\Dispute\ValueObject\DisputeActionSet} keeps.
 */
interface DisputeAction
{
    /**
     * Hands this action to a visitor, which is how a caller recovers the concrete type.
     *
     * `TReturn` is bound by the visitor's own type argument rather than declared on this method
     * alone, so a visitor that answers a question is typed at the call site — `?string`,
     * `Dispatchable`, whatever it produces — and the double dispatch is not read back as `mixed`.
     *
     * @template TReturn
     *
     * @param  DisputeActionVisitor<TReturn>  $visitor
     * @return TReturn
     */
    public function accept(DisputeActionVisitor $visitor): mixed;
}
