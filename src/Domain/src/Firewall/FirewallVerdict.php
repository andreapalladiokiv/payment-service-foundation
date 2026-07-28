<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Firewall;

/**
 * What a firewall chain concluded — the equivalent of an iptables target, plus
 * the case where the packet fell off the end.
 *
 * A firewall port never interprets these beyond reporting them; the consuming
 * policy maps them onto its own action. The fraud policy, for instance, maps
 * {@see Deny} onto a forced 3DS step-up (it never hard-blocks a payment, so
 * forcing authentication is the strongest action available to it) and
 * {@see Allow} onto letting a trusted transaction through.
 *
 * Shared across every domain's firewall port, so it stays free of any one
 * domain's vocabulary.
 *
 * {@see NoMatch} is deliberately a case of its own rather than an absent value:
 * a caller writing `match` over this enum is forced to say what silence means,
 * whereas a nullable verdict lets a stray `?? $default` decide it silently. That
 * distinction is where fail-open holes come from.
 */
enum FirewallVerdict: string
{
    /**
     * A rule matched and accepts the subject — the chain's whitelist exception.
     */
    case Allow = 'allow';

    /**
     * A rule matched and rejects the subject. What "reject" costs is the
     * consuming policy's business; the firewall only reports that a rule said
     * no.
     */
    case Deny = 'deny';

    /**
     * The chain was evaluated and nothing matched.
     *
     * This is NOT acceptance. Falling off the end of a chain is where the
     * caller's own default policy applies — which may be per-tenant, per-chain
     * or operator-configured, and the firewall cannot know it. Authors who want
     * the chain itself to answer can write a final catch-all rule: a rule with
     * no conditions matches everything.
     */
    case NoMatch = 'no_match';
}
