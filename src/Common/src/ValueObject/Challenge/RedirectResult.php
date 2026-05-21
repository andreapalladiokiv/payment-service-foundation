<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Challenge;

use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\ChallengeResultVisitor;

/**
 * Completion artefact for a {@see RedirectChallenge}. The cardholder returned
 * from the hosted page; the gateway already holds the transaction outcome and
 * communicates it via webhook. We carry only the original transaction id so
 * the adapter can correlate the return URL parameters with the gateway-side
 * record.
 */
final readonly class RedirectResult implements ChallengeResult
{
    public function __construct(
        public string $transactionId,
    ) {}

    public function accept(ChallengeResultVisitor $visitor): mixed
    {
        return $visitor->visitRedirect($this);
    }
}
