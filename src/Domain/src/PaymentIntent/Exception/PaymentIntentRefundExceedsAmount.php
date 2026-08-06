<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Money\Money;

final class PaymentIntentRefundExceedsAmount extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function create(Money $availableAmount, Money $requestedAmount): self
    {
        return self::coded(
            ErrorCode::RefundExceedsAvailableAmount,
            "Refund amount [{$requestedAmount->getAmount()}] exceeds available amount [{$availableAmount->getAmount()}].",
        );
    }
}
