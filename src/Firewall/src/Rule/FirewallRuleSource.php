<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Rule;

use Techork\PaymentService\Firewall\Chain\ChainStrategy;

/**
 * Supplies the rules of a chain, in evaluation order.
 *
 * This is the seam that keeps rule storage out of the engine: the application
 * owns the table, the admin UI and the ordering column, and implements this to
 * hand the engine a plain ordered sequence. It is also what lets a chain be
 * authored either in configuration or in an admin panel without the engine
 * knowing the difference.
 *
 * Implementations MUST return the rules already ordered — the engine takes the
 * first match and does not sort — and SHOULD return only enabled rules. An
 * unknown chain is not an error: return nothing, and the chain's strategy decides what an
 * empty chain concludes.
 *
 * A chain is its rules AND the policy they are exceptions to, so both come from here. The same
 * rule list means opposite things under a whitelist and under a blacklist, and a chain authored
 * in an admin panel needs somewhere to say which it is — which is why the strategy sits beside
 * the rules rather than in the engine's configuration.
 */
interface FirewallRuleSource
{
    /**
     * @param  string  $chain  opaque chain name in the caller's vocabulary
     * @return iterable<int, FirewallRule>
     */
    public function rulesFor(string $chain): iterable;

    /**
     * What the chain concludes for a subject none of its rules matched.
     *
     * @param  string  $chain  opaque chain name in the caller's vocabulary
     */
    public function strategyFor(string $chain): ChainStrategy;
}
