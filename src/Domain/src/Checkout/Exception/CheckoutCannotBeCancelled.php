<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Checkout\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Domain\Checkout\CheckoutStatus;

final class CheckoutCannotBeCancelled extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function withStatus(CheckoutStatus $status): self
    {
        return self::coded(
            ErrorCode::CheckoutUnexpectedState,
            "Checkout cannot be cancelled in status [$status->value].",
        );
    }
}
