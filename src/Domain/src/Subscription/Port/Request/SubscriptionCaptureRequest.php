<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Port\Request;

use Money\Money;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;
use Techork\PaymentService\Domain\Subscription\ValueObject\SubscriptionId;

/**
 * Everything the subscription can say about the capture it is asking for: which
 * subscription is activating, which intent to capture, and for how much.
 *
 * The amount is the plan's, never a caller's parameter — an activation cannot
 * capture for anything other than what the subscription is priced at.
 * `subscriptionId` travels so the implementation can commit the capture together
 * with the activation it belongs to.
 */
final readonly class SubscriptionCaptureRequest
{
    public function __construct(
        public SubscriptionId $subscriptionId,
        public PaymentIntentId $paymentIntentId,
        public Money $amount,
    ) {}
}
