<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Exception;

use InvalidArgumentException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Common\Contract\PaymentInstrument;

/**
 * A gateway was handed an instrument it has no product for on that operation —
 * a `HostedPayment` to an acquirer without a hosted page, raw card data to a
 * hosted-only gateway. Thrown from the `visit*()` branch that would otherwise
 * have to invent a payload.
 *
 * The operation is part of the message because support is per-operation, not
 * per-gateway: Stripe, Nuvei and Paynet all accept `hosted` on `purchase`
 * while rejecting it everywhere else.
 */
final class UnsupportedInstrument extends InvalidArgumentException implements UnsupportedByGateway
{
    use CarriesErrorCode;

    public static function forGateway(string $gatewayName, string $operation, PaymentInstrument $instrument): self
    {
        return self::coded(ErrorCode::UnsupportedByGateway, sprintf(
            'Gateway "%s" does not accept a "%s" instrument on the "%s" operation.',
            $gatewayName,
            $instrument::type(),
            $operation,
        ));
    }

    /**
     * For the inverse case: a gateway that accepts exactly one instrument kind
     * and is being handed anything else, where naming the one it wants is more
     * useful than naming the one it got.
     */
    public static function onlyAccepts(string $gatewayName, string $operation, string $acceptedType, PaymentInstrument $instrument): self
    {
        return self::coded(ErrorCode::UnsupportedByGateway, sprintf(
            'Gateway "%s" accepts only a "%s" instrument on the "%s" operation, got "%s".',
            $gatewayName,
            $acceptedType,
            $operation,
            $instrument::type(),
        ));
    }
}
