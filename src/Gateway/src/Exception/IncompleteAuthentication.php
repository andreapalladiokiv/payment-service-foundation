<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Exception;

use InvalidArgumentException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\ValueObject\ErrorCode;

/**
 * A gateway was handed a 3DS attestation missing fields it requires in order to
 * forward the authentication. Nuvei declares `eci`, `cavv` and `dsTransID` all
 * Required inside its external-MPI block; Stripe's 3DS import requires the ECI
 * on every network except Cartes Bancaires.
 *
 * Distinct from {@see UnsupportedInstrument} and {@see UnsupportedOperation}: the
 * gateway does support forwarding an authentication, it was handed an incomplete
 * one. It carries {@see UnsupportedByGateway} for the same reason those do —
 * assembling the request without the missing field posts a body the gateway
 * rejects, and that rejection reaches the event stream as `PaymentIntentFailed`,
 * i.e. as an issuer decline for a request no issuer ever saw.
 *
 * The usual way to get here is an attestation that did not succeed: a 3DS result
 * with status N, R or U carries no authentication value. Whether the domain
 * should refuse such a result earlier, before a port is spent, is a separate
 * question — but it does not license the adapter to quietly drop it, because a
 * transaction submitted without the cryptogram the caller believes it sent is
 * one that silently lost its liability shift.
 */
final class IncompleteAuthentication extends InvalidArgumentException implements UnsupportedByGateway
{
    use CarriesErrorCode;

    /**
     * @param  non-empty-list<string>  $missingFields  Gateway-native field names, so
     *   the message points at the wire contract rather than at our own value object.
     */
    public static function missingFields(string $gatewayName, string $operation, array $missingFields): self
    {
        return self::coded(ErrorCode::InvalidAuthenticationResult, sprintf(
            'Gateway "%s" cannot forward a 3DS authentication on the "%s" operation: missing %s.',
            $gatewayName,
            $operation,
            implode(', ', $missingFields),
        ));
    }
}
