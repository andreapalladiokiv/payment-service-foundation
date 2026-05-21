<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Removes a gateway-side reference for an instrument (e.g. when the gateway
 * reports that a saved PaymentMethod was detached). The local instrument
 * record itself is preserved.
 */
interface InstrumentReferenceEraser
{
    public function forgetPaymentMethodReference(GatewayId $gatewayId, string $reference): bool;
}
