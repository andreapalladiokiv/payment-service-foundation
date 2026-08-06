<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;

final class InvalidCheckoutPlan extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function amountMismatch(): self
    {
        return self::coded(
            ErrorCode::AmountMismatch,
            'Subscription plan amount must equal the checkout amount.',
        );
    }
}
