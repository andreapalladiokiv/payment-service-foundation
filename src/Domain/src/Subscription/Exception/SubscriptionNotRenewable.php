<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Domain\Subscription\SubscriptionStatus;

final class SubscriptionNotRenewable extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function withStatus(SubscriptionStatus $status): self
    {
        return self::coded(
            ErrorCode::SubscriptionUnexpectedState,
            "Subscription cannot be renewed in status [$status->value].",
        );
    }

    public static function pendingCancellation(): self
    {
        return self::coded(
            ErrorCode::SubscriptionUnexpectedState,
            'Subscription cannot be renewed while a cancellation is pending.',
        );
    }
}
