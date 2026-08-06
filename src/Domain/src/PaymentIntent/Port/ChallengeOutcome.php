<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeResult;

/**
 * What came of asking for a step-up: one of three, and the payment goes a different way for each.
 *
 * Three because an authentication has three endings, not two. 3DS is the case that proves it —
 * an authentication request comes back frictionless (`Y`/`A`: the issuer is satisfied, there is
 * nothing for the cardholder to do, and the liability shift is already earned), or needing the
 * cardholder (`C`), or rejected outright (`N`/`R`). A contract that could only answer "here is a
 * challenge" or "I could not raise one" had nowhere to put the first and the last, and the
 * frictionless case is the common one — so the usual outcome of a successful authentication
 * would have been an error.
 *
 * {@see raised()} parks the payment. {@see passed()} sends it to the acquirer carrying the
 * evidence, which is the whole point of authenticating. {@see refused()} fails it.
 *
 * The three are distinguished by which payload is present, and the constructor is private so
 * they cannot be mixed: a refusal is the one with neither.
 */
final readonly class ChallengeOutcome
{
    private function __construct(
        public ?Challenge $challenge = null,
        public ?ChallengeResult $result = null,
        public ?string $reason = null,
    ) {}

    /**
     * The cardholder must do something, and here is what to present them with.
     *
     * The artefact is required. An implementation that decided a step-up is needed but obtained
     * nothing to show has not raised one — it has {@see refused()}, and saying so gives the
     * payment an ending instead of parking it on something no client can act on.
     */
    public static function raised(Challenge $challenge): self
    {
        return new self(challenge: $challenge);
    }

    /**
     * Authentication completed with no interaction needed, and this is the evidence.
     *
     * The result travels to the gateway with the payment. Note that it is the provider's answer
     * and not necessarily the caller's copy of it: {@see ChallengePort::verify()} returns what
     * the provider says, which is the point of asking.
     */
    public static function passed(ChallengeResult $result): self
    {
        return new self(result: $result);
    }

    /**
     * Authentication will not happen or did not succeed, so the payment ends.
     *
     * Covers an issuer's rejection, evidence that did not check out, and a step-up this payment
     * cannot carry out at all — a merchant-initiated charge has no cardholder to answer one.
     * The reason is recorded on the failure; it is a breadcrumb, not control flow.
     */
    public static function refused(?string $reason = null): self
    {
        return new self(reason: $reason);
    }

    public function wasRaised(): bool
    {
        return $this->challenge !== null;
    }

    public function wasPassed(): bool
    {
        return $this->result !== null;
    }

    public function wasRefused(): bool
    {
        return $this->challenge === null && $this->result === null;
    }
}
