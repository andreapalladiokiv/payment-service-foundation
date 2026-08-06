<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengeOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\InitiateChallengeRequest;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\VerifyChallengeRequest;

/**
 * Answers whatever a test needs from an authentication, and records which question it was asked.
 *
 * The recorded request is the point of half of it: whether the aggregate started an
 * authentication or weighed one already presented is the difference between the two things that
 * can happen when a chain demands a step-up, and it turns on nothing but whether a result came in
 * with the payment.
 */
final class StubChallengePort implements ChallengePort
{
    public ?InitiateChallengeRequest $initiated = null;

    public ?VerifyChallengeRequest $verified = null;

    private function __construct(private readonly ChallengeOutcome $outcome) {}

    public static function raising(Challenge $challenge): self
    {
        return new self(ChallengeOutcome::raised($challenge));
    }

    public static function passing(ChallengeResult $result): self
    {
        return new self(ChallengeOutcome::passed($result));
    }

    public static function refusing(?string $reason = 'stub refused'): self
    {
        return new self(ChallengeOutcome::refused($reason));
    }

    public function initiate(InitiateChallengeRequest $request): ChallengeOutcome
    {
        $this->initiated = $request;

        return $this->outcome;
    }

    public function verify(VerifyChallengeRequest $request): ChallengeOutcome
    {
        $this->verified = $request;

        return $this->outcome;
    }
}
