<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout;

enum CheckoutStatus: string
{
    case Pending = 'pending';
    case Charged = 'charged';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
