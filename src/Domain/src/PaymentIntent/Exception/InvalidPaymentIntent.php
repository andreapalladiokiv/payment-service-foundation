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

    /**
     * A hosted payment happens entirely on the gateway's own page: the buyer
     * enters their card there and the gateway decides when the money moves. We
     * hold no instrument to authorize now and capture later, so any capture
     * method other than `Immediate` describes a flow we cannot perform — and
     * every gateway in the fleet implements hosted on the charge path only.
     */
    public static function hostedPaymentRequiresImmediateCapture(string $captureMethod): self
    {
        return self::coded(
            ErrorCode::CaptureMethodUnsupported,
            "A hosted payment cannot use the \"$captureMethod\" capture method — the payment happens on the gateway's page, so only immediate capture is possible.",
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
