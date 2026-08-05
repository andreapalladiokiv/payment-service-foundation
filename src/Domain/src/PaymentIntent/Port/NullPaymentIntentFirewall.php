<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Override;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;

/**
 * Default {@see PaymentIntentFirewallPort} for a deployment that has not connected a firewall:
 * an empty chain under a blacklist, so everything is allowed.
 *
 * This is a stub, not a policy. A rule engine ships as a separate optional package
 * (techork/payment-service-firewall), and until it is installed there are no rules, no chain
 * and no strategy — so the honest behaviour is the one a payment service had before a firewall
 * existed at all.
 *
 * It used to deny everything, reasoning from "iptables -P INPUT DROP". That reads as prudent and
 * was the wrong analogy: a packet filter's default policy is a configured decision, while this
 * is the absence of one. Denying turned "the operator has not wired up the optional package"
 * into a step-up on every single payment, which is a self-inflicted outage dressed as caution.
 *
 * The trade is stated rather than hidden: with no firewall installed, nothing is inspected.
 * Applications SHOULD log when this implementation is in effect, because "no fraud inspection at
 * all" is a condition you want to discover from a log line rather than from a chargeback.
 */
final readonly class NullPaymentIntentFirewall implements PaymentIntentFirewallPort
{
    #[Override]
    public function evaluate(PaymentIntentFirewallRequest $request): FirewallDecision
    {
        return FirewallDecision::allow('firewall not installed', matched: false);
    }
}
