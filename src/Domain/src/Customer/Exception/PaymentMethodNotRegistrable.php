<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;

final class PaymentMethodNotRegistrable extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function alreadyRegistered(PaymentMethodId $paymentMethodId): self
    {
        return self::coded(
            ErrorCode::CustomerUnexpectedState,
            "Payment method {$paymentMethodId->toString()} is already registered to this customer.",
        );
    }
}
