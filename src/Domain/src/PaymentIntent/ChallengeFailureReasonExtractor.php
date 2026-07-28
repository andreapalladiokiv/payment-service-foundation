<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

use Techork\PaymentService\Common\Contract\ChallengeResultVisitor;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;

/**
 * Resolves a {@see \Techork\PaymentService\Common\Contract\ChallengeResult}
 * to a failure reason, or null if it represents a successful completion.
 *
 * 3DS: Successful (Y), NotAvailable (A) and Info (I) qualify as success. None
 * of the three means the issuer refused: Y authenticated the cardholder, A
 * proves the attempt, and I is what the directory server returns when the
 * requestor asked for no challenge — it still carries an authentication value
 * and an ECI. Only an outright refusal (N, R) or a failure to evaluate (U)
 * is a failure.
 *
 * Redirect: a RedirectResult is only constructed when the cardholder
 * actually returned from the hosted page; failure paths are surfaced via
 * a separate webhook channel, not as a RedirectResult.
 *
 * @implements ChallengeResultVisitor<?string>
 */
final class ChallengeFailureReasonExtractor implements ChallengeResultVisitor
{
    public function visitThreeDS(ThreeDSResult $result): ?string
    {
        return in_array($result->status, [
            ThreeDSStatus::Successful,
            ThreeDSStatus::NotAvailable,
            ThreeDSStatus::Info,
        ], true)
            ? null
            : "3DS authentication: {$result->status->value}";
    }

    public function visitRedirect(RedirectResult $result): ?string
    {
        return null;
    }
}
