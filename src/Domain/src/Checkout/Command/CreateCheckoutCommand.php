<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Command;

use Money\Money;
use Techork\PaymentService\Domain\Checkout\ValueObject\CheckoutId;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionPlan;

interface CreateCheckoutCommand
{
    public function checkoutId(): CheckoutId;

    public function amount(): Money;

    public function description(): ?string;

    public function callbackUrl(): ?string;

    public function expiresAt(): ?\DateTimeImmutable;

    /** @return array<string, mixed> */
    public function metadata(): array;

    public function plan(): ?SubscriptionPlan;
}
