<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Gateway\Role\AcquiringGateway;
use Techork\PaymentService\Gateway\Role\CardIssuer;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * A payment provider, as this codebase talks to one.
 *
 * Two methods of its own; everything else it can do arrives through the two composites, and each
 * of those is a union of roles a caller depends on one at a time. Nothing here takes a parameter
 * array, returns a request for someone else to send, or extends anything.
 *
 * What it used to be: an extension of Omnipay's `GatewayInterface`, which declared no operations
 * at all, so every verb reached its provider by duck typing and a missing one surfaced as a
 * merchant-facing decline. The verbs are typed now, and so is the configuration that used to
 * arrive as a bag.
 */
interface Gateway extends AcquiringGateway, CardIssuer
{
    public function getName(): string;

    /**
     * Called once, by {@see \Techork\PaymentService\Gateway\GatewayFactory}, before the gateway is
     * used. A driver reads the settings it needs into typed properties here and builds whatever
     * clients it talks to; there is no second call to invalidate what the first one derived.
     */
    public function configure(GatewayInfrastructure $infrastructure): void;
}
