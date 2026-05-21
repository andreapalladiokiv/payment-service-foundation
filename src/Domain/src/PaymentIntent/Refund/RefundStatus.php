<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund;

enum RefundStatus: string
{
    case Processed = 'processed';
    case Failed = 'failed';
}
