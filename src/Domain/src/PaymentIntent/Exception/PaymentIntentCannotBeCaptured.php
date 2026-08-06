<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;

final class PaymentIntentCannotBeCaptured extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function withStatus(PaymentIntentStatus $status): self
    {
        return self::coded(
            ErrorCode::PaymentIntentUnexpectedState,
            "PaymentIntent cannot be captured in status [$status->value].",
        );
    }

    public static function immediate(): self
    {
        return self::coded(
            ErrorCode::CaptureMethodUnsupported,
            'PaymentIntent capture_method is immediate; capture happens inline at create.',
        );
    }
}
