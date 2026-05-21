<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

final readonly class CancelRequest
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
    ) {}
}
