<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Port\Request;

use Money\Money;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * Everything the checkout can say about the capture it is asking for, and nothing
 * it cannot: which checkout is asking, which intent to capture, and for how much.
 *
 * The amount is the checkout's own, never a parameter a caller chose — a checkout
 * cannot capture for more or less than it is for. `checkoutId` travels so the
 * implementation can commit the capture together with the checkout that caused
 * it.
 */
final readonly class CheckoutCaptureRequest
{
    public function __construct(
        public CheckoutId $checkoutId,
        public PaymentIntentId $paymentIntentId,
        public Money $amount,
    ) {}
}
