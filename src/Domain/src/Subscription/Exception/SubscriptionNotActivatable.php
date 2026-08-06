<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\Subscription\SubscriptionStatus;

final class SubscriptionNotActivatable extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function withStatus(SubscriptionStatus $status): self
    {
        return self::coded(
            ErrorCode::SubscriptionUnexpectedState,
            "Subscription cannot be activated in status [$status->value].",
        );
    }

    /**
     * Authorized, not charged: activation is what decides the money may be
     * taken, so it must still be takeable. An intent charged inline at create
     * moved the money before any of these checks ran, and the same one could
     * then activate a second subscription with nothing left to refuse.
     */
    public static function paymentIntentNotAuthorized(PaymentIntentStatus $status): self
    {
        return self::coded(
            ErrorCode::PaymentIntentUnexpectedState,
            "Subscription activation requires an authorized payment intent (got [$status->value]).",
        );
    }

    public static function amountMismatch(): self
    {
        return self::coded(
            ErrorCode::AmountMismatch,
            'Payment intent amount does not match subscription plan amount.',
        );
    }
}
