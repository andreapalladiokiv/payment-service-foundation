<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use EventSauce\EventSourcing\AggregateRootId;

final class InvalidPaymentIntent extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function nonPositiveAmount(): self
    {
        return self::coded(
            ErrorCode::InvalidChargeAmount,
            'Payment intent amount must be positive.',
        );
    }

    public static function alreadyExists(AggregateRootId $id): self
    {
        return self::coded(
            ErrorCode::ResourceAlreadyExists,
            "Payment intent {$id->toString()} already exists and cannot be imported over.",
        );
    }

    public static function unusablePaymentSource(): self
    {
        return self::coded(
            ErrorCode::PaymentMethodUnexpectedState,
            'Payment source is not usable (expired or consumed).',
        );
    }

    public static function challengeResultCarriesNoEvidence(string $reason): self
    {
        return self::coded(
            ErrorCode::InvalidAuthenticationResult,
            "Cannot confirm a challenge on an incoherent result: $reason.",
        );
    }
}
