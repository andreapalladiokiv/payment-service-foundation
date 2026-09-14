<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Port;

use Techork\PaymentService\Domain\Dispute\DisputeAggregate;
use Techork\PaymentService\Domain\Dispute\Port\Request\AvailableActionsRequest;
use Techork\PaymentService\Domain\Dispute\ValueObject\DashboardDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DisputeActionSet;
use Techork\PaymentService\Domain\Dispute\ValueObject\RespondDisputeAction;

/**
 * What can still be done about a case, asked of the provider that holds it.
 *
 * ## The one requirement this port exists for
 *
 * **An empty set never means "this provider offers no API action".** It means the case is not
 * waiting on us: it was won, lost, accepted, expired, or challenging it is disallowed by the rules.
 * A provider whose cases are answered by a human in a portal — ConnexPay, whose CMS API is read-only
 * — answers a **non-empty** set carrying a {@see DashboardDisputeAction} and the deep link into the
 * portal. The two states are different statements about the case, and the whole action model is
 * built to keep them apart; an implementation that collapsed them would report every ConnexPay case
 * as closed.
 *
 * The same reasoning is why a provider with no dispute surface at all — Paynet, Revolut — has **no
 * binding for this port** rather than an implementation answering with nothing: "out of scope" and
 * "nothing to do here" are not the same answer, and an empty set would state the wrong one about
 * every case.
 *
 * ## The domain states the ceiling; the provider narrows it
 *
 * Which actions a case admits is stated once, by the case itself
 * ({@see DisputeAggregate::availableDisputeActions()}), and this port is how that answer is asked
 * **as of now**: the live read is the one fact the aggregate cannot see, and the window between
 * deliveries is when a case is lost, so an implementation applies the provider's own state to the
 * ceiling as a **veto that can only subtract**. Nuvei's per-case `availableActions[]` is the
 * clearest case of it — a case sitting in `FC-SPCSE` (dispute response not allowed) offers nothing
 * while its neighbour in `FC` offers a response — and no adapter may add an action the case itself
 * does not admit: an action offered on a case that is not waiting on us is an irreversible call on a
 * case somebody else already decided.
 *
 * The one shape that is neither a ceiling nor a subtraction is ConnexPay's, which *replaces* the
 * response with a {@see DashboardDisputeAction} because its cases are not answered through an API at
 * all. Each adapter that does that says so in its own docblock rather than presenting it as
 * narrowing.
 *
 * ## What an action set is not
 *
 * It is not an authorisation and not a promise. The case can move between this read and the call it
 * suggests — a deadline can pass, the network can decide — so an adapter that acts on a stale set
 * still reports the business refusal in its own outcome
 * ({@see AcceptOutcome::alreadyClosed()} is exactly that fact for an acceptance).
 */
interface DisputeActionsPort
{
    /**
     * The actions still open on the case, or the statement that none is.
     *
     * Every action we can perform carries the provider's own response deadline, so a caller can
     * build an operator task from the answer without a second read. A {@see RespondDisputeAction}
     * carries the evidence requirements for the pair the provider states on the case where the table
     * has them, and stays null where it does not; a {@see DashboardDisputeAction} carries the portal
     * link and nothing else.
     */
    public function availableActions(AvailableActionsRequest $request): DisputeActionSet;
}
