<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Command;

use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionPlan;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;

interface CreateSubscriptionCommand
{
    public function subscriptionId(): SubscriptionId;

    public function plan(): SubscriptionPlan;

    public function paymentMethodId(): PaymentMethodId;

    public function callbackUrl(): ?string;

    /** @return array<string, mixed> */
    public function metadata(): array;
}
