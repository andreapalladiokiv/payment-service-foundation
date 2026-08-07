<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use LogicException;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Domain\PaymentIntent\Exception\ChallengeCannotBeRaised;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengeOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\InitiateChallengeRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\VerifyChallengeRequest;

/**
 * Answers whatever a test needs from an authentication, and records which question it was asked.
 *
 * The recorded request is the point of half of it. What the port is given is what an
 * implementation has to work from — this card, this amount, this payment — so a test that cares
 * about binding reads it rather than trusting the aggregate passed it. And whether the aggregate
 * STARTED an authentication or weighed one already presented is the difference between the two
 * things that can happen when a chain demands a step-up, which turns on nothing but whether a
 * result came in with the payment.
 *
 * Each factory sets up one of the two methods and leaves the other unarmed, because no single
 * payment reaches both: a test that trips the unarmed one has routed the payment down a branch it
 * did not mean to, and hearing about that is the point.
 */
final class StubChallengePort implements ChallengePort
{
    public ?InitiateChallengeRequest $initiated = null;

    public ?VerifyChallengeRequest $verified = null;

    private function __construct(
        private readonly ?ChallengeOutcome $outcome = null,
        private readonly ?Challenge $raises = null,
        private readonly ?string $unraisable = null,
    ) {}

    /** An authenticator that hands back a step-up to present. */
    public static function raising(Challenge $challenge): self
    {
        return new self(raises: $challenge);
    }

    /** An authenticator with nobody to authenticate on this payment. */
    public static function unableToRaise(string $why = 'no cardholder present'): self
    {
        return new self(unraisable: $why);
    }

    public static function passing(ChallengeResult $result): self
    {
        return new self(ChallengeOutcome::passed($result));
    }

    public static function refusing(?string $reason = 'stub refused'): self
    {
        return new self(ChallengeOutcome::refused($reason));
    }

    public function initiate(InitiateChallengeRequest $request): Challenge
    {
        $this->initiated = $request;

        if ($this->unraisable !== null) {
            throw ChallengeCannotBeRaised::notPossibleForThisPayment($this->unraisable);
        }

        return $this->raises ?? throw new LogicException('This stub was not set up to start an authentication.');
    }

    public function verify(VerifyChallengeRequest $request): ChallengeOutcome
    {
        $this->verified = $request;

        return $this->outcome ?? throw new LogicException('This stub was not set up to weigh a presented authentication.');
    }
}
