<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Risk;

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;

/**
 * Everything a {@see \Techork\PaymentService\Common\Contract\FraudScreeningProvider}
 * needs to score a card transaction, kept inside the PII/PCI boundary: billing
 * details, the PCI-safe card summary (BIN + last four), the amount, and the
 * connection signals. No passenger PII, no raw PAN, no CVV.
 *
 * The amount is carried as minor units + ISO currency code rather than a
 * {@see \Money\Money} so the Common kernel stays free of the money library;
 * higher layers convert. `reference` is the caller-generated fraud reference
 * (UUID) the provider echoes as the order/verification id.
 */
final readonly class FraudScreeningRequest
{
    public function __construct(
        public string $reference,
        public CardSummary $card,
        public BillingAddress $billing,
        public int $amountMinorUnits,
        public string $currencyCode,
        public ConnectionContext $connection,
    ) {}
}
