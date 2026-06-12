<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Money\Money;

/**
 * Optional capability for Omnipay-style gateway responses: surface the FX
 * settled amount when the gateway exposes one. Returns null when there's no
 * such signal in the response (no FX applied, or the gateway / adapter
 * doesn't carry it). The router folds non-null values onto the result so
 * the domain port can hand them to the aggregate.
 */
interface ConvertedAmountProvider
{
    public function getConvertedAmount(): ?Money;
}
