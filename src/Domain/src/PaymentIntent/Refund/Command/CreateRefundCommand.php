<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Command;

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;

interface CreateRefundCommand
{
    public function refundId(): RefundId;

    public function amount(): Money;

    /**
     * Optional alternative payment instrument: when present the refund is
     * credited to it instead of going back to the original payment source.
     * Used when the original card can't accept the refund (expired, closed).
     */
    public function retryInstrument(): ?PaymentInstrument;
}
