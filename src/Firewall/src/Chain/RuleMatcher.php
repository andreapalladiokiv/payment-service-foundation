<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Chain;

use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Firewall\Exception\UnevaluableChain;
use Techork\PaymentService\Firewall\Rule\FirewallRule;
use Throwable;

/**
 * Answers whether one rule matches the subject under evaluation.
 *
 * The collaborator a {@see ChainStrategy} walks with. It exists so a strategy can be about
 * TRAVERSAL and nothing else: which rules to visit, in what order, and when to stop. How a rule
 * is matched — compiled expressions, the structured matcher set, the fact-bag sandbox — stays
 * behind this one method, so a new strategy needs to know none of it.
 *
 * It is also where an unevaluable rule becomes {@see UnevaluableChain}, deliberately rather than
 * per strategy. If each strategy caught its own failures, one of them would eventually decide a
 * broken rule was survivable and reintroduce the fail-open this package spent a redesign closing:
 * a Deny rule with a typo, skipped, sitting above an Allow that matched.
 */
final readonly class RuleMatcher
{
    /**
     * @param  array<string, mixed>  $facts  root-keyed; the key set is the sandbox
     */
    public function __construct(
        private RuleEvaluator $evaluator,
        private string $chain,
        private array $facts,
    ) {}

    /**
     * @throws UnevaluableChain when the rule cannot be evaluated at all
     */
    public function matches(FirewallRule $rule): bool
    {
        try {
            return $this->evaluator->matches($rule->conditions, $this->facts, $rule->expression);
        } catch (Throwable $e) {
            throw UnevaluableChain::rule($this->chain, $rule->id, $e);
        }
    }

    public function chain(): string
    {
        return $this->chain;
    }
}
