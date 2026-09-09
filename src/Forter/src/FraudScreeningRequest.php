<?php

declare(strict_types=1);

namespace Techork\PaymentService\Forter;

use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;

/**
 * Everything a {@see FraudScreeningProvider}
 * needs to score a card transaction, kept inside the PII/PCI boundary: the
 * customer, the PCI-safe card summary (BIN + last four), the amount, and the
 * connection signals. No passenger PII, no raw PAN, no CVV.
 *
 * The customer rather than a billing address, because screening reads the payer as
 * much as the place — Forter's `accountOwner` is a name and an email, its
 * `billingDetails.personalDetails` a name again — and those fields used to be on the
 * address, which is what made the address the de-facto identity.
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
        public Customer $customer,
        public int $amountMinorUnits,
        public string $currencyCode,
        public ConnectionContext $connection,
    ) {}
}
