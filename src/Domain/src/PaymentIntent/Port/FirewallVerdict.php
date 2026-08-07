<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

/**
 * What a firewall chain decided — one of three actions, and all three are actions.
 *
 * A rule declares one of these as what to do when it matches, and a chain answers with one.
 * There is deliberately no "nothing matched" case: silence is not an action, and a caller
 * handed one has no basis for interpreting it. What the absence of a match means belongs to
 * the chain, which says so through its
 * {@see \Techork\PaymentService\Firewall\Chain\ChainStrategy} — a whitelist ends in Deny, a
 * blacklist in Allow — so the answer that reaches a caller is always something it can act on.
 *
 * That case used to exist, documented as "the caller's own default policy applies — which may
 * be per-tenant, per-chain or operator-configured, and the firewall cannot know it". No
 * mechanism for expressing such a policy ever followed, so the one caller collapsed NoMatch,
 * Deny and an unevaluable chain into a single branch and fabricated a 3DS challenge for all
 * three. Policy that has nowhere to live does not stay at the caller; it gets lost.
 *
 * Shared across every domain's firewall port, so it stays free of any one domain's vocabulary.
 */
enum FirewallVerdict: string
{
    /**
     * Let the subject through.
     */
    case Allow = 'allow';

    /**
     * Refuse the subject outright.
     *
     * Not a request for more evidence: a denied payment is one that must not happen, so no
     * authentication can answer it. Consuming policies map this onto their own refusal.
     */
    case Deny = 'deny';

    /**
     * Let the subject through only once it has passed a challenge.
     *
     * The middle answer, and the reason a firewall need not choose between waving a suspicious
     * payment through and refusing a legitimate one. It is only that if it is carried out: a
     * consuming domain that answers this by refusing has two spellings of {@see Deny} and no
     * middle at all.
     *
     * What the challenge IS belongs to whoever can raise it — 3DS is the usual one — reached
     * through that domain's own port, which holds the instrument a firewall deliberately never
     * sees. For payments that is
     * {@see \Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort}.
     */
    case Challenge = 'challenge';
}
