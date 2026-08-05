<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Override;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;

/**
 * Default {@see PaymentIntentFirewallPort}: denies everything.
 *
 * A rule engine ships as a separate, optional package
 * (techork/payment-service-firewall), so the domain must be able to stand
 * without one. That default is FAIL-CLOSED, following the convention a packet
 * filter uses for a policy it cannot evaluate — `iptables -P INPUT DROP`.
 *
 * Note this is NOT the same situation as a chain that falls through, which
 * returns {@see FirewallDecision::noMatch()} so the caller's own default policy
 * applies. Here the firewall is absent altogether: there is no chain, no policy
 * and nothing to defer to, so the safe answer is the only answer. For a fraud
 * policy that means a forced 3DS step-up — a degraded but safe posture, not a
 * blocked payment, and often frictionless for the cardholder.
 *
 * Because the resulting posture is uniform and severe, applications SHOULD log
 * when this implementation is in effect: "authentication required for
 * everything" is a loud failure, but without a breadcrumb it is a slow one to
 * locate.
 */
final readonly class NullPaymentIntentFirewall implements PaymentIntentFirewallPort
{
    #[Override]
    public function evaluate(PaymentIntentFirewallRequest $request): FirewallDecision
    {
        return FirewallDecision::deny('firewall not installed');
    }
}
