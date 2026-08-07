<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use LogicException;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort;

/**
 * A firewall chain demanded a step-up that this installation cannot carry out — because nothing
 * is wired to authenticate anyone, or because nothing can authenticate anyone on this payment.
 *
 * A `LogicException`, unlike everything else in this directory: those describe a payment that
 * cannot be made and an application turns them into a refusal, while this describes rules
 * written in terms the installation cannot honour. An
 * application that mapped it onto a decline would report an ordinary payment as refused and
 * leave the misconfiguration in place.
 *
 * Not a refusal for the same reason it is not a pass. Waving the payment through would let a
 * chain's step-up rules quietly stop protecting anything the moment nobody wired an
 * authenticator; failing it silently would blame the cardholder for a wiring mistake. Neither
 * is an answer, so this is thrown and an operator reads it.
 */
final class ChallengeCannotBeRaised extends LogicException
{
    public static function noPortInstalled(?string $reason): self
    {
        $reason ??= 'no reason given';
        $port = ChallengePort::class;

        return new self("A firewall chain required a step-up ($reason) but no $port is installed to authenticate anyone.");
    }

    /**
     * For a {@see ChallengePort} asked to start an authentication this payment cannot carry out:
     * a merchant-initiated charge with no cardholder to answer, an instrument with no pan to
     * authenticate against, a channel with nowhere to render an ACS page.
     *
     * The same class as a missing port, and deliberately: both are a chain matching traffic it
     * was never able to protect, and both are fixed by scoping the rule rather than by anything
     * the cardholder or the merchant can do to this payment. The `payment_intent.initiation`
     * fact exists so a chain can say "not this traffic" and never reach here.
     *
     * `$why` is the implementation's own words, for the operator who has to find the rule.
     */
    public static function notPossibleForThisPayment(string $why): self
    {
        return new self("A firewall chain required a step-up but this payment cannot be authenticated: $why");
    }
}
