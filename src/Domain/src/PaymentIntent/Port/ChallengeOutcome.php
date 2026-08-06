<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Common\Contract\ChallengeResult;

/**
 * What an authentication presented with a payment turned out to be worth.
 *
 * Two endings, and it briefly had three. The third was "here is a challenge to present", from
 * when this port also started authentications — which a server-to-server payment cannot do,
 * having no cardholder session to conduct one in. Raising a step-up now belongs to the
 * authentication endpoints a merchant drives directly, so what reaches this aggregate is always
 * a finished authentication, and the only question left is whether it holds up.
 *
 * {@see passed()} sends the payment to the acquirer carrying the evidence, which is the whole
 * point of authenticating. {@see refused()} fails it. The two are told apart by which payload is
 * present, and the constructor is private so they cannot be mixed.
 */
final readonly class ChallengeOutcome
{
    private function __construct(
        public ?ChallengeResult $result = null,
        public ?string $reason = null,
    ) {}

    /**
     * The authentication is genuine, current, and about this payment — and this is the evidence.
     *
     * The result travels to the gateway with the payment, and it is the PROVIDER's answer rather
     * than the caller's copy of it wherever the two can differ: {@see ChallengePort::verify()}
     * returns what the record says, which is the point of asking.
     */
    public static function passed(ChallengeResult $result): self
    {
        return new self(result: $result);
    }

    /**
     * The evidence does not hold up, so the payment ends.
     *
     * Covers an issuer's rejection and a result that failed verification — spent already, issued
     * for another card or another amount, or never issued by us at all. A caller cannot tell
     * those apart and must not: distinguishing them out loud would tell someone probing us which
     * part of a forgery to fix. The reason is recorded on the failure for an operator; it is a
     * breadcrumb, not control flow.
     */
    public static function refused(?string $reason = null): self
    {
        return new self(reason: $reason);
    }

    public function wasPassed(): bool
    {
        return $this->result !== null;
    }

    public function wasRefused(): bool
    {
        return $this->result === null;
    }
}
