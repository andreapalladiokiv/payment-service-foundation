<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Exception;

use InvalidArgumentException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\ValueObject\ErrorCode;

/**
 * An instrument was offered for storage with nobody to store it for.
 *
 * Registering is the one operation where the customer is not optional, and the reason is the
 * providers': Stripe will not make a PaymentMethod reusable without a customer, and Nuvei cannot
 * hand back a `userPaymentOptionId` without a `userTokenId`. A stored card belongs to someone —
 * that is what makes it storable rather than a one-off charge.
 *
 * **Not a decline, and it must not be folded into one.** An empty customer used to be the case a
 * nullable parameter swallowed: the caller had nothing to say, the provider was handed the
 * address that rode along with the payment, and a customer got invented out of it. That is the
 * behaviour `docs/customer-domain-plan` exists to end, so it is a typed refusal here rather than
 * a `RegistrationResult::failed()` — a caller cannot mistake a wiring mistake of its own for an
 * issuer's verdict. Same rule as {@see UnsupportedInstrument}: foundation states the invariant,
 * the application checks what it can before calling.
 */
final class RegistrationNeedsCustomer extends InvalidArgumentException
{
    use CarriesErrorCode;

    /**
     * Asked to create a provider-side customer without saying which of ours it is.
     *
     * Refused rather than defaulted. The value a default would reach for is the email, and an
     * email-keyed provider customer is the state F5 removed: Nuvei documents `userTokenId` as what
     * "uniquely identifies your consumer/user in your system", so a change of address makes a
     * different person of them and orphans their stored cards. A3 exists to migrate away from
     * exactly that, and a silent fallback would keep minting it.
     */
    public static function toRegisterAt(string $gatewayName): self
    {
        return self::coded(ErrorCode::CustomerUnexpectedState, sprintf(
            'Gateway "%s" was asked to register a customer without being told which of ours it is.',
            $gatewayName,
        ));
    }

    public static function forGateway(string $gatewayName): self
    {
        return self::coded(ErrorCode::CustomerUnexpectedState, sprintf(
            'Gateway "%s" was asked to register an instrument without naming the customer it belongs to.',
            $gatewayName,
        ));
    }
}
