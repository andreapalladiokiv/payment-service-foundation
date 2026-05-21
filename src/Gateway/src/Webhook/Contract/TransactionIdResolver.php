<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Reverse lookup: gateway-side reference (e.g. Stripe pi_xxx, Nuvei
 * PPP_TransactionID) → our internal aggregate id (UUID string), scoped by
 * gateway_id. Returning a string keeps this package free of domain VOs; the
 * caller wraps into a typed aggregate id at the domain boundary.
 */
interface TransactionIdResolver
{
    public function resolvePaymentIntent(GatewayId $gatewayId, string $reference): ?string;

    public function resolveRefund(GatewayId $gatewayId, string $reference): ?string;
}
