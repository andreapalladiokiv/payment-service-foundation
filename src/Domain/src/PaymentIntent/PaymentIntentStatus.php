<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

enum PaymentIntentStatus: string
{
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case Charged = 'charged';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
