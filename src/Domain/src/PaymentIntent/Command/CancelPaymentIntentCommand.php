<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Command;

use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

interface CancelPaymentIntentCommand
{
    public function paymentIntentId(): PaymentIntentId;

    public function reason(): string;
}
