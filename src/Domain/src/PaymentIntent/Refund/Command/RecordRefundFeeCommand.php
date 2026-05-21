<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Command;

use DateTimeImmutable;
use Money\Money;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;

interface RecordRefundFeeCommand
{
    public function refundId(): RefundId;

    public function fee(): Money;

    public function observedAt(): DateTimeImmutable;
}
