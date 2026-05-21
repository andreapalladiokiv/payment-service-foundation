<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook;

use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Looks up the per-transaction `serviceFee` ConnexPay charges. Webhook
 * payloads don't carry the fee — only Search/Sales and Search/Purchases
 * expose it — so handlers fetch it on-demand when they receive a
 * settlement / authorization event.
 *
 * Returns `null` when fee data isn't available yet (typical right after
 * an authorization but before settlement) or when the lookup fails;
 * the handler should map this to {@see HandlerOutcome::Skipped} and
 * rely on webhook delivery retries to fire again once data is ready.
 */
interface ServiceFeeFetcher
{
    /**
     * Sales-API row keyed by `SaleGuid`.
     */
    public function fetchSaleFee(GatewayId $gatewayId, string $saleGuid): ?Money;

    /**
     * Purchases-API row keyed by `CardGuid`.
     */
    public function fetchPurchaseFee(GatewayId $gatewayId, string $cardGuid): ?Money;
}
