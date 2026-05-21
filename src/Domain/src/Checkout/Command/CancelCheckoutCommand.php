<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Command;

use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;

interface CancelCheckoutCommand
{
    public function checkoutId(): CheckoutId;
}
