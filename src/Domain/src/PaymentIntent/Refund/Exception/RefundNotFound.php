<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;

final class RefundNotFound extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function withId(RefundId $id): self
    {
        return self::coded(ErrorCode::ResourceMissing, "Refund [{$id->toString()}] not found on this payment intent.");
    }
}
