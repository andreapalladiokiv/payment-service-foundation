<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;

final class PaymentIntentCannotBeRefunded extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function withStatus(PaymentIntentStatus $status): self
    {
        return self::coded(
            ErrorCode::PaymentIntentUnexpectedState,
            "PaymentIntent cannot be refunded in status [$status->value].",
        );
    }
}
