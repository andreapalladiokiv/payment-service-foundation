<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Money\Money;

/**
 * Result of a {@see ConfirmChallengePort::confirm()} call: the payment is placed.
 *
 * Constructed only through {@see self::placed()}, so an implementation states that
 * outcome rather than arriving at it by returning an empty object. It used to be a bag
 * of nullables whose every field was empty on the webhook path, which made a considered
 * answer indistinguishable from a forgotten one.
 *
 * There is no second challenge to report, and that is a fact about 3DS rather than a
 * simplification. A confirmed authentication is presented to the acquirer with the
 * authorization, and the issuer does not then ask for another — even `transStatus` `I`,
 * which reports that no check was performed, tells the requestor that the issuer will
 * not invoke one. So the answers are "placed" or a refusal, and a refusal travels as
 * {@see GatewayDeclinedException}. A gateway that somehow produced a further challenge
 * would be describing something nobody can complete: on the webhook path the cardholder
 * left when the announcement was sent, and on the path where we raise the challenge
 * ourselves there is no ACS session to return to.
 *
 * No acquirer identity here either. The domain does not know what happens in the
 * infrastructure, and whether a reference exists, what it is and where it is kept is the
 * port's business, as on every other path.
 */
final readonly class ConfirmChallengeOutcome
{
    private function __construct(public ?Money $convertedAmount = null) {}

    /**
     * `$convertedAmount` only when the FX figure is genuinely known: an announcement does
     * not always carry one, and inferring it from the requested amount would record a
     * number the acquirer never quoted.
     */
    public static function placed(?Money $convertedAmount = null): self
    {
        return new self($convertedAmount);
    }
}
