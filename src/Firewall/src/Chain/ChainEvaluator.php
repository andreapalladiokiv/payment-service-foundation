<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Chain;

use Psr\Log\LoggerInterface;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallVerdict;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Firewall\Rule\FirewallRuleSource;
use Throwable;

/**
 * Walks one chain in order and returns the first rule that matches.
 *
 * This is the shared machinery behind every domain's firewall port: the ports
 * are typed to their own domain data and are responsible for assembling facts,
 * then they delegate the actual chain walk here. It deals only in a chain name
 * and a fact bag, which is why it can be reused across domains.
 *
 * Fail-safety is the delicate part. A single malformed rule must never break the
 * caller's flow, so an unevaluable rule is skipped — but skipping silently is how
 * a chain quietly stops protecting anything. So a skip is both logged and
 * recorded on the decision as {@see FirewallDecision::$degraded}, on EVERY
 * outcome including a match: the dangerous case is a reject rule that threw
 * sitting above an accept rule that matched, where the result looks clean but is
 * not. The caller decides what a degraded chain is worth; this class only refuses
 * to hide it.
 *
 * Falling off the end of the chain returns {@see FirewallDecision::noMatch()} —
 * never a fabricated verdict. The default policy belongs to the caller.
 */
final readonly class ChainEvaluator
{
    public function __construct(
        private FirewallRuleSource $rules,
        private RuleEvaluator $evaluator,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  array<string, mixed>  $facts  root-keyed; the key set is the sandbox
     */
    public function evaluate(string $chain, array $facts): FirewallDecision
    {
        $degraded = false;

        foreach ($this->rules->rulesFor($chain) as $rule) {
            try {
                if (! $this->evaluator->matches($rule->conditions, $facts, $rule->expression)) {
                    continue;
                }
            } catch (Throwable $e) {
                $degraded = true;
                $this->logger?->error('Skipping unevaluable firewall rule', [
                    'chain' => $chain,
                    'rule' => $rule->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $reason = $rule->id !== null ? "matched rule {$rule->id}" : 'matched rule';

            return $rule->verdict === FirewallVerdict::Deny
                ? FirewallDecision::deny($reason, $degraded)
                : FirewallDecision::allow($reason, $degraded);
        }

        return FirewallDecision::noMatch('no rule matched', $degraded);
    }
}
