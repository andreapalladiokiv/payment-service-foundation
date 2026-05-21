<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

/**
 * Implemented by gateway responses that return a customer reference alongside
 * the transaction reference (e.g., ConnexPay's verify endpoint, which returns
 * both the card GUID and the customer GUID). The router uses this to expose
 * the customer reference through {@see GatewayResult}.
 */
interface CustomerReferenceProvider
{
    public function getCustomerReference(): ?string;
}
