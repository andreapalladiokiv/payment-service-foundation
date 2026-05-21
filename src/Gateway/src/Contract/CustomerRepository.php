<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @internal Used by gateway provider packages only.
 *
 * Stores customer references and links them to instrument references via
 * a pivot table. Each provider's CreatePaymentMethodRequest (or equivalent)
 * creates a customer independently and links it to the instrument's
 * gateway_reference when both are known.
 */
interface CustomerRepository
{
    /**
     * Returns the customer reference linked to the given instrument at
     * this gateway, if any.
     */
    public function findByInstrument(GatewayId $gatewayId, PaymentInstrument $instrument): ?string;

    /**
     * Upserts the customer reference for this gateway and links it to the
     * instrument's gateway_reference. The instrument's gateway_reference
     * must already exist.
     */
    public function saveAndAttach(GatewayId $gatewayId, PaymentInstrument $instrument, string $customerReference): void;
}
