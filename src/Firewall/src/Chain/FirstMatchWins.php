<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Chain;

use Override;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallVerdict;

/**
 * The classic packet-filter walk: top to bottom, the first rule that matches decides, and a
 * default policy answers a subject none of them mentioned.
 *
 * What iptables and RouterOS do, and the reason expressing OR means writing another rule. Rules
 * below a match are never visited, which is what makes rule order meaningful and what lets a
 * narrow exception sit above a broad prohibition.
 *
 * Whitelist and blacklist are this traversal with opposite defaults rather than traversals of
 * their own — the walk is identical, only the answer for an unmentioned subject differs. Hence
 * the two named constructors: they are the vocabulary operators think in, and both are one
 * argument to the same algorithm.
 */
final readonly class FirstMatchWins implements ChainStrategy
{
    private function __construct(
        private FirewallVerdict $fallthrough,
        private string $name,
    ) {}

    /**
     * Nothing is permitted unless a rule says so.
     *
     * The posture of a default-DROP filter. For a chain whose rules enumerate what is acceptable:
     * a rule someone forgot to write refuses rather than admits.
     */
    public static function whitelist(): self
    {
        return new self(FirewallVerdict::Deny, 'whitelist');
    }

    /**
     * Everything is permitted unless a rule says otherwise.
     *
     * The posture of a fraud chain, where rules enumerate what is suspicious and ordinary traffic
     * falls through untouched. Fail-open by construction, which is the right trade for a chain
     * whose job is catching exceptions — but an empty blacklist permits everything, so a
     * deployment that has not authored its rules should know that is what it has.
     */
    public static function blacklist(): self
    {
        return new self(FirewallVerdict::Allow, 'blacklist');
    }

    /**
     * Any other default, for a chain whose fallthrough is neither of the two common postures —
     * a chain that challenges what it does not recognise, for instance.
     */
    public static function withDefault(FirewallVerdict $fallthrough, string $name): self
    {
        return new self($fallthrough, $name);
    }

    #[Override]
    public function walk(iterable $rules, RuleMatcher $matcher): FirewallDecision
    {
        foreach ($rules as $rule) {
            if (! $matcher->matches($rule)) {
                continue;
            }

            return self::decide(
                $rule->verdict,
                $rule->id !== null ? "matched rule {$rule->id}" : 'matched rule',
            );
        }

        return self::decide($this->fallthrough, "no rule matched ({$this->name})", matched: false);
    }

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The verdict decides which factory reports it. Kept as one exhaustive match so a new action on
     * {@see FirewallVerdict} cannot be silently dropped into the wrong outcome — there is no type
     * error to catch that, only this.
     */
    private static function decide(FirewallVerdict $verdict, string $reason, bool $matched = true): FirewallDecision
    {
        return match ($verdict) {
            FirewallVerdict::Allow => FirewallDecision::allow($reason, $matched),
            FirewallVerdict::Deny => FirewallDecision::deny($reason, $matched),
            FirewallVerdict::Challenge => FirewallDecision::challenge($reason, $matched),
        };
    }
}
