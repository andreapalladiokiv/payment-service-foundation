<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Domain\Subscription\SubscriptionStatus;

final class SubscriptionNotCancellable extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function withStatus(SubscriptionStatus $status): self
    {
        return self::coded(
            ErrorCode::SubscriptionUnexpectedState,
            "Subscription cannot be cancelled in status [$status->value].",
        );
    }

    public static function alreadyScheduled(): self
    {
        return self::coded(
            ErrorCode::SubscriptionUnexpectedState,
            'Subscription cancellation is already scheduled.',
        );
    }

    public static function notScheduled(): self
    {
        return self::coded(
            ErrorCode::SubscriptionUnexpectedState,
            'Subscription cancellation is not scheduled.',
        );
    }

    public static function alreadyPending(): self
    {
        return self::coded(
            ErrorCode::SubscriptionUnexpectedState,
            'Subscription cancellation is already pending.',
        );
    }
}
