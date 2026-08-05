<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Challenge;

use Techork\PaymentService\Common\Contract\Challenge;

/**
 * Raises the step-up a chain asked for.
 *
 * The seam between deciding that a subject must be challenged and actually challenging it. The
 * firewall does the first and cannot do the second: a {@see Challenge} is evidence that a
 * handoff to an external system has already happened and carries what the client needs to
 * render it — an ACS url, a creq, a transaction id the directory server issued. None of that
 * can be derived from a rule.
 *
 * This contract exists because the alternative was tried and was worse. With nowhere to obtain
 * a real challenge, the payment-intent aggregate constructed
 * `new ThreeDSChallenge(transactionId: $paymentIntentId)`: every rendering field null, so no
 * client could act on it, and the payment intent's own id handed out where an authentication
 * reference belongs. A fabricated artefact is not a smaller version of a real one.
 *
 * 3DS is the expected implementation and not the only conceivable one — the interface names a
 * challenge, not a protocol.
 *
 * Returning null is allowed and means "I could not raise one". The chain's verdict stands: the
 * subject still may not proceed without a challenge, and the caller records that the
 * authentication is required and un-started rather than pretending it began. Implementations
 * throw only for infrastructure they cannot reach, the same rule every other port here follows.
 */
interface ChallengeInitiator
{
    /**
     * @param  array<string, mixed>  $facts  the chain's fact bag, root-keyed — the same view the
     *                                      rules matched on, so an initiator can act on what
     *                                      the decision was actually made from
     */
    public function initiate(string $chain, array $facts): ?Challenge;
}
