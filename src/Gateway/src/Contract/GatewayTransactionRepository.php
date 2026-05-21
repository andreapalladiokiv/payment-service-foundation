<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Stores and retrieves gateway transaction references for payment intents
 * and refunds — the gateway-side identifiers for charge/auth/refund operations.
 */
interface GatewayTransactionRepository
{
    public function findForPaymentIntent(string $paymentIntentId): ?string;

    public function saveForPaymentIntent(GatewayId $gatewayId, string $paymentIntentId, string $reference): void;

    public function findForRefund(string $refundId): ?string;

    public function saveForRefund(GatewayId $gatewayId, string $refundId, string $reference): void;
}
