<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Domain\Checkout\CheckoutStatus;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;

final class CheckoutNotPayable extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function withStatus(CheckoutStatus $status): self
    {
        return self::coded(
            ErrorCode::CheckoutUnexpectedState,
            "Checkout cannot be paid in status [$status->value].",
        );
    }

    public static function expired(): self
    {
        return self::coded(ErrorCode::CheckoutExpired, 'Checkout has expired.');
    }

    public static function paymentIntentNotAuthorized(PaymentIntentStatus $status): self
    {
        return self::coded(
            ErrorCode::PaymentIntentUnexpectedState,
            "Payment intent is not authorized (status: $status->value).",
        );
    }

    public static function amountMismatch(): self
    {
        return self::coded(
            ErrorCode::AmountMismatch,
            'Payment intent amount does not match checkout amount.',
        );
    }

    public static function planSubscriptionMismatch(): self
    {
        return self::coded(
            ErrorCode::CheckoutPlanSubscriptionMismatch,
            'Checkout plan and PayCheckoutCommand subscription must both be set or both be null.',
        );
    }

    public static function subscriptionCancelled(): self
    {
        return self::coded(
            ErrorCode::SubscriptionUnexpectedState,
            'Cannot pay a checkout against a cancelled subscription.',
        );
    }

    public static function paymentIntentSubscriptionMismatch(): self
    {
        return self::coded(
            ErrorCode::PaymentIntentSubscriptionMismatch,
            'Payment intent is not the one bound to the subscription.',
        );
    }
}
