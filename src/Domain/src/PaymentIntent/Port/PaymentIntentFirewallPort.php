<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;

/**
 * Driven port the payment-intent flow consults, ahead of doing work, to be told
 * what a rule-driven risk policy has decided about the transaction at hand —
 * whether to demand stronger authentication, and prospectively whether to
 * proceed at all.
 *
 * Each aggregate declares its own firewall port, typed to the data that
 * aggregate actually holds; they share only the {@see FirewallDecision}
 * vocabulary. That is why this takes a typed
 * {@see PaymentIntentFirewallRequest} rather than a loose fact bag: the caller
 * hands over what it has, and the implementation is responsible for everything
 * else — which facts the rules may inspect, and where they come from (BIN and IP
 * intelligence, fraud screening).
 *
 * Rules are evaluated packet-filter style: in chain order, first match wins, so
 * expressing OR means writing another rule. The chain is named on the request,
 * so an inspection at card registration never weighs a rule written about
 * authorization.
 *
 * POLICY-FREE BY CONTRACT. Implementations MUST NOT throw for a business
 * outcome, MUST NOT substitute a default when nothing matches, and MUST NOT
 * decide fail-open versus fail-closed. A chain that matches nothing returns
 * {@see FirewallDecision::noMatch()} and the CALLER applies its own default
 * policy, which may vary per gateway or per chain.
 *
 * The default implementation is {@see NullPaymentIntentFirewall}, which denies.
 * Installing a rule engine is what makes a chain evaluable; see the
 * techork/payment-service-firewall package.
 */
interface PaymentIntentFirewallPort
{
    public function evaluate(PaymentIntentFirewallRequest $request): FirewallDecision;
}
