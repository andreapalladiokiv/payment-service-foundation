<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use Override;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeAction;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeActionVisitor;
use Techork\PaymentService\Domain\Dispute\ValueObject\AcceptDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\DashboardDisputeAction;
use Techork\PaymentService\Domain\Dispute\ValueObject\RespondDisputeAction;

/**
 * The one implementation of {@see DisputeActionVisitor} in this tree, and it exists to be exercised
 * rather than to be used.
 *
 * `src/` ships none deliberately — see the interface's own docblock: nothing consumes an action set
 * yet, and no gateway's capability is a strict subset of what a set can carry — so without this the
 * visitor would be untested code on the only path a caller has for recovering an action's concrete
 * type. What it answers is the action's own kind, which is what a round trip has to prove.
 *
 * `$last` is deliberate rather than incidental: a visitor that reported the right kind while being
 * handed a different instance would pass a name-only assertion, and the concrete `accept()` methods
 * are one line each precisely because the argument's identity is the whole of their contract.
 *
 * The type argument is `string`, which is also the check that `@template TReturn` binds: with the
 * bound parameter missing, psalm resolves `TReturn` to `mixed` and — at `errorLevel="3"`, where the
 * whole `MIXED_ISSUES` family is off — says nothing at all.
 *
 * @implements DisputeActionVisitor<string>
 */
final class ActionKindVisitor implements DisputeActionVisitor
{
    /** The last action handed to this visitor, so a test can assert which instance arrived. */
    public ?DisputeAction $last = null;

    #[Override]
    public function visitRespond(RespondDisputeAction $action): string
    {
        $this->last = $action;

        return 'respond';
    }

    #[Override]
    public function visitAccept(AcceptDisputeAction $action): string
    {
        $this->last = $action;

        return 'accept';
    }

    #[Override]
    public function visitDashboard(DashboardDisputeAction $action): string
    {
        $this->last = $action;

        return 'dashboard';
    }
}
