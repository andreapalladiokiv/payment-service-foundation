<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

/**
 * The outcome of evaluating one firewall chain — the whole of what a domain's
 * firewall port returns.
 *
 * This is the shared decision vocabulary: each domain declares its own typed
 * firewall port (see
 * {@see PaymentIntentFirewallPort}),
 * and they all answer in these terms.
 *
 * Deliberately minimal. A firewall reports what its rules decided and nothing
 * more: it carries no policy action vocabulary and no provider payload.
 *
 * The verdict is always present — {@see FirewallVerdict::NoMatch} covers the
 * chain that matched nothing, so a caller can `match` over three cases and be
 * forced to say what each means, rather than defaulting an absent value by
 * accident.
 *
 * `$reason` is an abstract breadcrumb for debugging and audit — a rule
 * identifier, "no rule matched", "firewall not installed". It is documentation,
 * never control flow: callers MUST NOT parse it.
 *
 * `$degraded` reports that at least one rule in the chain could not be
 * evaluated and was skipped. It exists so that skipping cannot silently weaken
 * a decision: a chain whose reject rule threw otherwise looks identical to one
 * that legitimately accepted, and a caller that treats those the same has a
 * fail-open hole. It rides on every outcome, including a match. Callers MUST NOT
 * treat a degraded result as a clean evaluation.
 */
final readonly class FirewallDecision
{
    private function __construct(
        public FirewallVerdict $verdict,
        public ?string $reason = null,
        public bool $degraded = false,
    ) {}

    /**
     * A rule matched and accepts the subject.
     */
    public static function allow(?string $reason = null, bool $degraded = false): self
    {
        return new self(FirewallVerdict::Allow, $reason, $degraded);
    }

    /**
     * A rule matched and rejects the subject.
     */
    public static function deny(?string $reason = null, bool $degraded = false): self
    {
        return new self(FirewallVerdict::Deny, $reason, $degraded);
    }

    /**
     * The chain was evaluated and nothing matched — the caller applies its own
     * default policy.
     */
    public static function noMatch(?string $reason = null, bool $degraded = false): self
    {
        return new self(FirewallVerdict::NoMatch, $reason, $degraded);
    }

    /**
     * Whether this decision lets the subject through.
     *
     * This is the domain's policy, stated once so no caller has to re-derive it:
     * ONLY an explicit {@see FirewallVerdict::Allow} on a chain that evaluated
     * cleanly permits. Everything else — a rejection, a chain that matched
     * nothing, a chain that could not be fully evaluated — does not. Fail-closed
     * in every direction, the way a packet filter treats a policy it cannot
     * satisfy.
     *
     * Note the {@see $degraded} clause: it is what stops a rejecting rule that
     * failed to compile from becoming an accepted subject, which is the shape a
     * fail-open hole actually takes here.
     */
    public function permits(): bool
    {
        return $this->verdict === FirewallVerdict::Allow && ! $this->degraded;
    }

    /**
     * True when a rule matched, either way. Useful for audit and diagnostics;
     * for "may this proceed?" use {@see permits()}.
     */
    public function matched(): bool
    {
        return $this->verdict !== FirewallVerdict::NoMatch;
    }

    public function isDenied(): bool
    {
        return $this->verdict === FirewallVerdict::Deny;
    }

    public function isAllowed(): bool
    {
        return $this->verdict === FirewallVerdict::Allow;
    }
}
