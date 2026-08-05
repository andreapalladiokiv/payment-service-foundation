<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Common\Contract\Challenge;

/**
 * The outcome of evaluating one firewall chain — the whole of what a domain's firewall port
 * returns.
 *
 * This is the shared decision vocabulary: each domain declares its own typed firewall port
 * (see {@see PaymentIntentFirewallPort}), and they all answer in these terms.
 *
 * Always an action. There is no "nothing matched" outcome, because a caller cannot act on
 * silence: what the absence of a match means is the chain's business and its strategy answers
 * it. And there is no `degraded` flag, because a chain that could not be fully evaluated has no
 * answer at all and says so by throwing — both of those used to be the caller's problem, and
 * both were quietly collapsed into one branch by the only caller there is.
 *
 * `$reason` is an abstract breadcrumb for debugging and audit — a rule identifier, "no rule
 * matched (blacklist)", "firewall not installed". It is documentation, never control flow:
 * callers MUST NOT parse it.
 *
 * `$matched` separates a decision a rule made from one the chain's fallthrough made. Both are
 * real answers; the difference matters when reading back why a payment went the way it did.
 *
 * `$challenge` carries the step-up to present when the verdict is
 * {@see FirewallVerdict::Challenge} and something was able to raise one. Null with that verdict
 * means authentication is required and nobody has initiated it yet — which is a truthful
 * answer, unlike a fabricated challenge with no ACS behind it.
 */
final readonly class FirewallDecision
{
    private function __construct(
        public FirewallVerdict $verdict,
        public ?string $reason = null,
        public bool $matched = false,
        public ?Challenge $challenge = null,
    ) {}

    /**
     * A rule matched and accepts the subject.
     */
    public static function allow(?string $reason = null, bool $matched = true): self
    {
        return new self(FirewallVerdict::Allow, $reason, $matched);
    }

    /**
     * A rule matched and rejects the subject.
     */
    public static function deny(?string $reason = null, bool $matched = true): self
    {
        return new self(FirewallVerdict::Deny, $reason, $matched);
    }

    /**
     * The subject may proceed only once it has passed a challenge.
     *
     * `$challenge` is what the client needs to present it. It is optional because the verdict is
     * a decision and the challenge is an artefact someone has to go and obtain: a deployment
     * with no challenge integration still reaches this outcome, and saying "required, none
     * raised" is honest where inventing one is not.
     */
    public static function challenge(?string $reason = null, bool $matched = true, ?Challenge $challenge = null): self
    {
        return new self(FirewallVerdict::Challenge, $reason, $matched, $challenge);
    }

    /**
     * Whether this decision lets the subject through as it stands.
     *
     * Only an explicit {@see FirewallVerdict::Allow}. A challenge does not permit yet — it
     * permits after the challenge is passed, which is a different payment state.
     */
    public function permits(): bool
    {
        return $this->verdict === FirewallVerdict::Allow;
    }

    public function requiresChallenge(): bool
    {
        return $this->verdict === FirewallVerdict::Challenge;
    }

    public function isDenied(): bool
    {
        return $this->verdict === FirewallVerdict::Deny;
    }

    public function isAllowed(): bool
    {
        return $this->verdict === FirewallVerdict::Allow;
    }

    public function withChallenge(Challenge $challenge): self
    {
        return new self($this->verdict, $this->reason, $this->matched, $challenge);
    }
}
