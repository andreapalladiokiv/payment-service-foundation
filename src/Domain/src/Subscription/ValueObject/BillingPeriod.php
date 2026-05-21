<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\ValueObject;

enum BillingPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
