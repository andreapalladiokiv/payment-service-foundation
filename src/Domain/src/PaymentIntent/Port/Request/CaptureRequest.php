<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Money\Money;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

final readonly class CaptureRequest
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public Money $amount,
    ) {}
}
