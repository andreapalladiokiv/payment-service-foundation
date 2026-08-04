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
 * The implemented case is the first one, which is the only kind of challenge that
 * exists so far: every one of them is the gateway's, and the port that answers it
 * makes no call at all. The second arrives with the flow that raises challenges of
 * our own, and brings its own implementation — an adapter is free to answer both
 * interfaces where the two calls happen to coincide, but nothing here assumes one
 * does.
 */
interface ConfirmChallengePort
{
    /**
     * @throws GatewayDeclinedException when the gateway refuses the payment the
     *                                  authentication was for
     */
    public function confirm(ConfirmChallengeRequest $request): ConfirmChallengeOutcome;
}
