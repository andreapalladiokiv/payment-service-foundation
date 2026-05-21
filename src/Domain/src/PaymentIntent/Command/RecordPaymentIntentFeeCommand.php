<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Command;

use DateTimeImmutable;
use Money\Money;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

interface RecordPaymentIntentFeeCommand
{
    public function paymentIntentId(): PaymentIntentId;

    public function fee(): Money;

    public function observedAt(): DateTimeImmutable;
}
