<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Chain;

use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Firewall\Challenge\ChallengeInitiator;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Firewall\Exception\UnevaluableChain;
use Techork\PaymentService\Firewall\Rule\FirewallRuleSource;

/**
 * Evaluates one chain: hands its rules to the strategy that says how the chain is walked, then
 * raises a challenge if the answer demanded one.
 *
 * This is the shared machinery behind every domain's firewall port. The ports are typed to their
 * own domain data and assemble facts; they delegate the walk here, which is why the same class
 * serves any domain — it deals only in a chain name and a fact bag.
 *
 * It no longer owns the traversal. It used to iterate the rules itself and take the first match,
 * which made that one algorithm the only one expressible: a chain that must visit every rule and
 * let the first Deny override an earlier Allow had nowhere to live. Now the traversal is a
 * {@see ChainStrategy} and this class supplies what any traversal needs — the rules, a
 * {@see RuleMatcher}, and the one step no strategy should have to remember.
 *
 * That step is the challenge. Raising it here rather than inside each strategy means a new
 * traversal cannot forget it, and means a strategy never touches the initiator, the facts or the
 * chain name.
 */
final readonly class ChainEvaluator
{
    public function __construct(
        private FirewallRuleSource $rules,
        private RuleEvaluator $evaluator,
        private ?ChallengeInitiator $challenges = null,
    ) {}

    /**
     * @param  array<string, mixed>  $facts  root-keyed; the key set is the sandbox
     *
     * @throws UnevaluableChain when a rule in the chain cannot be evaluated
     */
    public function evaluate(string $chain, array $facts): FirewallDecision
    {
        $decision = $this->rules->strategyFor($chain)->walk(
            $this->rules->rulesFor($chain),
            new RuleMatcher($this->evaluator, $chain, $facts),
        );

        if (! $decision->requiresChallenge() || $decision->challenge !== null) {
            return $decision;
        }

        // A null answer is kept as a null: "required, nobody raised one" is the truthful shape,
        // and it is what a deployment with no challenge integration gets. The verdict stands
        // either way — the subject may not proceed without a challenge.
        $raised = $this->challenges?->initiate($chain, $facts);

        return $raised === null ? $decision : $decision->withChallenge($raised);
    }
}
