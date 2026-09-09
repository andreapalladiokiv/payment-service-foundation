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
 * Only one factory, and the missing one is worth a line. There was a second, for a registration
 * of the CUSTOMER with no customer named — and it is gone because
 * {@see \Techork\PaymentService\Gateway\Command\RegisterCustomerCommand} types that field as a
 * {@see \Techork\PaymentService\Common\ValueObject\CustomerId}, so an absent one stopped
 * being expressible. A refusal a type has made unreachable is better than a refusal that fires.
 *
 * This one stays because {@see \Techork\PaymentService\Gateway\Command\VaultCommand} genuinely
 * has a nullable customer: it is shared with `tokenize()`, which has no customer at all, so the
 * requirement belongs to the operation rather than to the command.
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

    public static function forGateway(string $gatewayName): self
    {
        return self::coded(ErrorCode::CustomerUnexpectedState, sprintf(
            'Gateway "%s" was asked to register an instrument without naming the customer it belongs to.',
            $gatewayName,
        ));
    }
}
