<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Command;

use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Money\Money;

interface CapturePaymentIntentCommand
{
    public function paymentIntentId(): PaymentIntentId;

    public function amount(): Money;
}
