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
 * Implementations MUST NOT throw for a business outcome: an allow, a denial and a demand for a
 * challenge are all answers, returned as a {@see FirewallDecision}. They MUST throw when the
 * chain cannot be evaluated at all — a rule that fails to compile is a broken configuration, not
 * a verdict, and answering anything at that point would be a guess.
 *
 * A {@see FirewallVerdict::Challenge} decision MUST carry a {@see FirewallDecision::$challenge}.
 * The field is nullable so an implementation can decide first and attach the artefact after
 * obtaining it, not so that the pair can reach a caller apart: what the caller does with this
 * outcome is park the payment on something the client is to present, and a park with nothing to
 * present is a payment intent that can neither proceed nor be refused, indistinguishable from an
 * authentication still in flight. An implementation that cannot obtain one throws, exactly as it
 * does for a chain it cannot evaluate — the demand was unfulfillable, which is an operator's
 * problem and not the payment's.
 *
 * The chain decides what the absence of a match means, through its own strategy, and reports one
 * of three actions. That responsibility used to sit here: the port returned "nothing matched" and
 * the caller was told to apply its own default policy. No mechanism for expressing such a policy
 * followed, so the one caller folded it together with a denial and fabricated a 3DS challenge for
 * both — policy with nowhere to live does not stay at the caller, it gets lost.
 *
 * The default implementation is {@see NullPaymentIntentFirewall}, which allows everything because
 * no firewall is installed. Installing a rule engine is what makes a chain evaluable; see the
 * techork/payment-service-firewall package.
 */
interface PaymentIntentFirewallPort
{
    public function evaluate(PaymentIntentFirewallRequest $request): FirewallDecision;
}
