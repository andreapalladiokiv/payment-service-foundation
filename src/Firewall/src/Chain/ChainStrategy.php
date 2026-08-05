<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Chain;

use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Firewall\Rule\FirewallRule;

/**
 * How a chain is walked, and therefore what it concludes.
 *
 * The whole traversal, not a setting on someone else's. {@see FirstMatchWins} — top to bottom,
 * first match wins, a default policy at the end — is what iptables and RouterOS do, and it is the
 * only one that ships. It is an interface because it is not the only algorithm there is: a chain
 * that visits every rule and lets the first Deny override an earlier Allow, or one that answers
 * from a score rather than a match, is a different walk and not a different constant. Nothing here
 * assumes rule order matters, or that a match ends the walk.
 *
 * This started as `fallthrough(): FirewallVerdict`, which was not a strategy at all: it took no
 * context, so every implementation could only ever return a fixed verdict, and the traversal
 * stayed in the evaluator. The extension it advertised was unimplementable — "allow below a
 * threshold, challenge above it" needs the subject, and a constant provider never sees it.
 *
 * A strategy is handed the rules and a {@see RuleMatcher} and returns the decision. It does not
 * see facts, expressions or the DSL: matching is the matcher's business, so a new traversal is
 * written without learning any of that. It also never raises a challenge — the evaluator does
 * that afterwards, so that a strategy author cannot forget to.
 */
interface ChainStrategy
{
    /**
     * @param  iterable<int, FirewallRule>  $rules  in the order the source returned them
     */
    public function walk(iterable $rules, RuleMatcher $matcher): FirewallDecision;

    /**
     * A short identifier for audit — it lands in {@see FirewallDecision::$reason}, where it
     * explains which traversal produced the answer.
     */
    public function name(): string;
}
