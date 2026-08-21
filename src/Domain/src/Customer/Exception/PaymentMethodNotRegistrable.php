<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Exception;

use DomainException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Domain\Customer\ValueObject\CustomerId;

final class PaymentMethodNotRegistrable extends DomainException implements CodedError
{
    use CarriesErrorCode;

    /**
     * A card offered to the wrong customer.
     *
     * The one mistake {@see \Techork\PaymentService\Domain\Customer\ValueObject\AttachedPaymentMethod}
     * makes possible, and the reason the pairing is worth having: without it the collection would
     * take the card without complaint and then say it belongs to someone it does not. There is no
     * "names nobody" case to refuse — the type has a `CustomerId`, not a nullable string.
     */
    public static function belongsToAnotherCustomer(PaymentMethodId $paymentMethodId, CustomerId $customerId): self
    {
        return self::coded(
            ErrorCode::CustomerUnexpectedState,
            "Payment method {$paymentMethodId->toString()} belongs to customer {$customerId->toString()}, not to this one.",
        );
    }

    public static function alreadyRegistered(PaymentMethodId $paymentMethodId): self
    {
        return self::coded(
            ErrorCode::CustomerUnexpectedState,
            "Payment method {$paymentMethodId->toString()} is already registered to this customer.",
        );
    }
}
