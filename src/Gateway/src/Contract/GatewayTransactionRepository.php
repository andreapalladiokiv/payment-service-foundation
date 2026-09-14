<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Stores and retrieves gateway transaction references for payment intents,
 * refunds and disputes — the gateway-side identifiers for charge/auth/refund
 * operations and the provider's own reference for a dispute case.
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

    /**
     * The provider's own reference for a dispute case, keyed by **our** aggregate id for it.
     *
     * That direction is the point of the row. The four dispute port adapters hold our id and have
     * no way to address the provider's own call without the reference the case was stored under,
     * so `findForDispute` is what they read. The same row is read the other way — from the
     * provider's reference back to the aggregate — by
     * `EloquentTransactionIdResolver::resolveDispute()`, which is how a resolution arriving under
     * the provider's own name reaches the case it belongs to.
     *
     * `saveForDispute` is called by the {@see \Techork\PaymentService\Gateway\Webhook\Recorder\GatewayDisputeRecorder}
     * implementation when a case is observed — A0, which does not exist yet.
     */
    public function findForDispute(string $disputeId): ?string;

    public function saveForDispute(GatewayId $gatewayId, string $disputeId, string $reference): void;
}
