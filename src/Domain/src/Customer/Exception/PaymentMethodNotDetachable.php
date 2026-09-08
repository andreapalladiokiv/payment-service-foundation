<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;

final class PaymentMethodNotDetachable extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function notHeld(PaymentMethodId $paymentMethodId): self
    {
        return self::coded(
            ErrorCode::CustomerUnexpectedState,
            "This customer does not hold payment method {$paymentMethodId->toString()}.",
        );
    }
}
