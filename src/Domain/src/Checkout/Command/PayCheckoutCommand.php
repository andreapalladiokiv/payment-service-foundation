<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Command;

use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate;
use Techork\PaymentService\Domain\Subscription\SubscriptionAggregate;

interface PayCheckoutCommand
{
    public function checkoutId(): CheckoutId;

    public function paymentIntent(): PaymentIntentAggregate;

    public function subscription(): ?SubscriptionAggregate;
}
