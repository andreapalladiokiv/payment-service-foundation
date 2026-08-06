<?php

declare(strict_types=1);

namespace Techork\PaymentService\Tests\Support;

use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengeOutcome;
use Techork\PaymentService\Domain\PaymentIntent\Port\ChallengePort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\VerifyChallengeRequest;

/**
 * Answers whatever a test needs of a presented authentication, and records what it was shown.
 *
 * The recorded request is half the point: what the port is given is what an implementation has to
 * check the evidence against — this card, this amount, this payment — so a test that cares about
 * binding reads it rather than trusting the aggregate passed it.
 */
final class StubChallengePort implements ChallengePort
{
    public ?VerifyChallengeRequest $verified = null;

    private function __construct(private readonly ChallengeOutcome $outcome) {}

    public static function passing(ChallengeResult $result): self
    {
        return new self(ChallengeOutcome::passed($result));
    }

    public static function refusing(?string $reason = 'stub refused'): self
    {
        return new self(ChallengeOutcome::refused($reason));
    }

    public function verify(VerifyChallengeRequest $request): ChallengeOutcome
    {
        $this->verified = $request;

        return $this->outcome;
    }
}
