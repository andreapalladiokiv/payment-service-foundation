<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Chain;

use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Firewall\Exception\UnevaluableChain;
use Techork\PaymentService\Firewall\Rule\FirewallRuleSource;

/**
 * Evaluates one chain: hands its rules to the strategy that says how the chain is walked, and
 * returns what that walk decided.
 *
 * This is the shared machinery behind every domain's firewall port. The ports are typed to their
 * own domain data and assemble facts; they delegate the walk here, which is why the same class
 * serves any domain — it deals only in a chain name and a fact bag.
 *
 * It does not own the traversal. It used to iterate the rules itself and take the first match,
 * which made that one algorithm the only one expressible: a chain that must visit every rule and
 * let the first Deny override an earlier Allow had nowhere to live. Now the traversal is a
 * {@see ChainStrategy} and this class supplies what any traversal needs — the rules and a
 * {@see RuleMatcher}.
 *
 * It does not raise challenges either, and briefly it did. A `Challenge` verdict is a decision
 * that the subject must be authenticated, and this package cannot authenticate anyone: the facts
 * it works from hold a BIN and a last four and deliberately never a card number, so there was
 * nothing here to build an authentication request out of. Carrying it out belongs to a port of
 * the aggregate that holds the instrument —
 * {@see \Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort} — and the firewall's
 * answer stops at the decision.
 */
final readonly class ChainEvaluator
{
    public function __construct(
        private FirewallRuleSource $rules,
        private RuleEvaluator $evaluator,
    ) {}

    /**
     * @param  array<string, mixed>  $facts  root-keyed; the key set is the sandbox
     *
     * @throws UnevaluableChain when a rule in the chain cannot be evaluated
     */
    public function evaluate(string $chain, array $facts): FirewallDecision
    {
        return $this->rules->strategyFor($chain)->walk(
            $this->rules->rulesFor($chain),
            new RuleMatcher($this->evaluator, $chain, $facts),
        );
    }
}
