<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout;

/**
 * No `Failed` case: nothing can produce it. A capture either moves the money —
 * `Charged` — or throws without recording anything, leaving the checkout
 * `Pending` and payable again, because a capture only fails for infrastructural
 * reasons and a retry is the right answer to those. A checkout that should not be
 * retried is `Cancelled`.
 */
enum CheckoutStatus: string
{
    case Pending = 'pending';
    case Charged = 'charged';
    case Cancelled = 'cancelled';
}
