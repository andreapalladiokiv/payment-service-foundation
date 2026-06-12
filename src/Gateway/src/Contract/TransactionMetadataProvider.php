<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

/**
 * Optional capability for Omnipay-style gateway responses: surface
 * gateway-specific transaction attributes that must be persisted alongside
 * the transaction reference. Strict parallel to {@see CardChecksProvider} /
 * {@see CustomerReferenceProvider}: the router folds a non-empty array onto
 * the result, and the ports hand it to {@see GatewayTransactionRepository}.
 *
 * Prime example: ConnexPay's `connexPayTransaction.incomingTransCode` — the
 * merchant-facing transaction id the API contract exposes as `acquirer_id`.
 * It only exists in the sale/capture response body, so losing it here means
 * a gateway round-trip (or a backfill) later.
 *
 * @see GatewayResult::$metadata
 */
interface TransactionMetadataProvider
{
    /**
     * @return array<string, mixed> empty array when the response carries
     *                              no metadata worth persisting
     */
    public function getTransactionMetadata(): array;
}
