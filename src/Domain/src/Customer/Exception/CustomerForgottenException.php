<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;

/**
 * This customer's identity was erased at their request, and something tried to add to it.
 *
 * Refused rather than allowed quietly, because the aggregate cannot tell whether a new identity
 * belongs to the same person — that judgement is the host's, and reviving a forgotten customer by
 * side effect would make an erasure reversible by accident.
 *
 * Taking things away still works: a payment method that has to be released at the provider must
 * be releasable here whatever the customer's state.
 */
final class CustomerForgottenException extends DomainException implements CodedError
{
    use CarriesErrorCode;

    public static function cannotChange(string $what): self
    {
        return self::coded(
            ErrorCode::CustomerUnexpectedState,
            "Customer has been forgotten; $what cannot be changed.",
        );
    }
}
