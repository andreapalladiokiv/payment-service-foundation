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

    /**
     * @param array<string, mixed> $metadata gateway-specific transaction
     *                                       attributes (e.g. ConnexPay's
     *                                       incoming transaction code); an
     *                                       empty array leaves any previously
     *                                       stored metadata untouched
     */
    public function saveForPaymentIntent(GatewayId $gatewayId, string $paymentIntentId, string $reference, array $metadata = []): void;

    /**
     * @return array<string, mixed> the metadata stored with the payment
     *                              intent's reference; empty when none
     */
    public function findMetadataForPaymentIntent(string $paymentIntentId): array;

    public function findForRefund(string $refundId): ?string;

    public function saveForRefund(GatewayId $gatewayId, string $refundId, string $reference): void;
}
