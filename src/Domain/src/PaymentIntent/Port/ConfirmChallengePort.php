<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\Request\ConfirmChallengeRequest;

/**
 * Completes a payment whose cardholder has just answered an authentication.
 *
 * Deliberately not {@see CreatePort}. What the two ask for coincides in one case
 * and not the other, and the difference is whether a payment exists yet:
 *
 *  - the gateway raised the challenge, which means it opened the payment before
 *    doing so. Nothing is to be created; the authentication resolved
 *    gateway-side and the announcement of it is a webhook.
 *  - we raised the challenge, because inspection would not let the payment reach
 *    the acquirer until the cardholder had answered. Only now is there a payment
 *    to place, and the result goes with it as the evidence that claims the
 *    liability shift.
 *
 * Both kinds of challenge exist by now, but only the first has a shipped adapter
 * — `ExternallyCompletedConfirmChallengePort`, which makes no call at all. The
 * second is already raised, by `PaymentIntentAggregate::firewallRefusal()` when
 * inspection does not permit the payment, and still awaits an implementation that
 * actually places it — an adapter is free to answer both interfaces where the two
 * calls happen to coincide, but nothing here assumes one does.
 */
interface ConfirmChallengePort
{
    /**
     * @throws GatewayDeclinedException when the gateway refuses the payment the
     *                                  authentication was for
     */
    public function confirm(ConfirmChallengeRequest $request): ConfirmChallengeOutcome;
}
