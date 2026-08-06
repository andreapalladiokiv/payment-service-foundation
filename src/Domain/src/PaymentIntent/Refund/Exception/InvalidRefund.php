<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Refund\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Money\Currency;

final class InvalidRefund extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function nonPositiveAmount(): self
    {
        return self::coded(ErrorCode::InvalidChargeAmount, 'Refund amount must be positive.');
    }

    public static function currencyMismatch(Currency $expected, Currency $actual): self
    {
        return self::coded(ErrorCode::CurrencyMismatch, "Refund currency [{$actual->getCode()}] does not match payment intent currency [{$expected->getCode()}].");
    }
}
