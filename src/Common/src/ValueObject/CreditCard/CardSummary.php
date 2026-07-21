<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\CreditCard;

use InvalidArgumentException;
use Techork\PaymentService\Common\ValueObject\CardBrand;

/**
 * The PCI-safe projection of a card: BIN (first six) + last four + brand +
 * expiration + holder, and never the full PAN. This is the only card shape
 * that crosses into fraud screening and BIN intelligence — providers score on
 * BIN and last-four, so raw PAN must never reach them.
 */
final readonly class CardSummary
{
    public function __construct(
        public string $bin,
        public string $last4,
        public CardBrand $brand,
        public Expiration $expiration,
        public Holder $holder,
    ) {
        if (preg_match('/^\d{6}$/', $bin) !== 1) {
            throw new InvalidArgumentException('BIN must be exactly 6 digits.');
        }

        if (preg_match('/^\d{4}$/', $last4) !== 1) {
            throw new InvalidArgumentException('Last four must be exactly 4 digits.');
        }
    }
}
