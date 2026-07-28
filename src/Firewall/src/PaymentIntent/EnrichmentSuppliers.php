<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\PaymentIntent;

use Techork\PaymentService\Common\Contract\FactSupplier;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;

/**
 * Builds the enrichment suppliers for one assessment.
 *
 * Suppliers are bound to their inputs at construction — a screening supplier
 * holds a screening request, a BIN supplier holds a BIN and an IP — so they
 * cannot be created once and reused across assessments. This is the seam that
 * creates them per request, and it is what keeps this package free of any
 * vendor: the application implements it and decides which providers take part,
 * while nothing here learns what Forter or Neutrino are.
 *
 * The order returned is the precedence order — later suppliers win on a leaf
 * collision — so put the better source last.
 *
 * Implementations MUST NOT perform the lookups themselves; a supplier does its
 * own work when asked for facts. Returning a supplier is cheap, and a chain that
 * never reaches a rule referencing an enrichment fact should not pay for it.
 */
interface EnrichmentSuppliers
{
    /**
     * @return iterable<int, FactSupplier>
     */
    public function for(PaymentIntentFirewallRequest $request): iterable;
}
