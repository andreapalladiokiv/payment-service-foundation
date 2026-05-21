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
 * 3DS: Successful (Y) and NotAvailable (A) qualify as success — both grant
 * the liability shift. Other statuses are treated as failures.
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
        return in_array($result->status, [ThreeDSStatus::Successful, ThreeDSStatus::NotAvailable], true)
            ? null
            : "3DS authentication: {$result->status->value}";
    }

    public function visitRedirect(RedirectResult $result): ?string
    {
        return null;
    }
}
