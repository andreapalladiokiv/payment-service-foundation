<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Challenge;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Firewall\Exception\ChallengeNotRaised;

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
 * ## What an implementation is given, and what it is not
 *
 * The facts are the view the rules matched on, and that is all they are. They are deliberately
 * not a transport for what an ACS call needs: the bag holds `payment_method.source.bin` and
 * `last4` and never a PAN, and it carries nothing about the browser beyond `connection.ip` and
 * `connection.user_agent`. An authentication request wants more than that — the pan, the expiry,
 * the holder, a device fingerprint, the requestor url.
 *
 * Widening the schema to carry them would be the wrong repair twice over: those values are not
 * matchable evidence, and a fact bag that doubles as an argument list turns every new
 * integration's requirements into a change to the rule vocabulary an admin panel offers.
 *
 * What the bag does carry is a handle — `payment_intent.id` — and an implementation is expected
 * to use it: read the instrument from wherever the application keeps it (it holds the vault; the
 * firewall does not), and take request-scoped context like a browser fingerprint or an origin
 * through its own constructor, from the flow that is calling. An initiator is application code
 * with application collaborators, not a pure function of the facts.
 *
 * ## Returning null
 *
 * Null means "I could not raise one" and ends the chain in {@see ChallengeNotRaised} — it is not
 * a softer answer. A verdict that demands a challenge with no challenge behind it is exactly the
 * unusable outcome this vocabulary exists to prevent, and the reasoning is written out there.
 * Implementations throw for infrastructure they cannot reach, the same rule every other port here
 * follows; the two paths differ in what an operator reads, not in what a payment does.
 */
interface ChallengeInitiator
{
    /**
     * @param  string  $chain  opaque chain name, for an initiator that treats chains differently
     * @param  array<string, mixed>  $facts  the chain's fact bag, root-keyed — the same view the
     *                                      rules matched on, so an initiator can act on what the
     *                                      decision was actually made from, and can key off
     *                                      `payment_intent.id` for what the bag does not hold
     */
    public function initiate(string $chain, array $facts): ?Challenge;
}
