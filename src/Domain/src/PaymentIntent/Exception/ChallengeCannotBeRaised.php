<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use LogicException;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort;

/**
 * A firewall chain demanded a step-up on a deployment that has nothing to carry one out with.
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
}
