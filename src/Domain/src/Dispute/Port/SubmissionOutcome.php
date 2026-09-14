<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Port;

use InvalidArgumentException;
use Techork\PaymentService\Domain\Dispute\SubmissionState;

/**
 * What the submission call did, in the one term the aggregate records.
 *
 * Not `void`, and the reason is the submission axis. "We called the provider's submit endpoint" is
 * three different facts about our side of the case, and the caller cannot derive which one it got:
 *
 * ```
 * Stripe   submit: false   evidence staged on the case, invisible to the issuer   -> Draft
 * Nuvei    file uploaded   the file is at the provider, its callback not yet heard -> UploadPending
 * both     submit: true    the response has gone out                              -> Submitted
 * ```
 *
 * F7's two-step flow and F8's upload-then-submit flow both live inside one method because that is
 * the question the aggregate has; a `void` return would force the application to guess which of the
 * three happened and write it into a command, and the guess would be wrong for one provider.
 *
 * **The state cannot be `None` or `Confirmed`, and that is refused rather than clamped.**
 * `None` is the absence of any submission, which a call that just returned did not produce; and
 * `Confirmed` is the provider's own acknowledgement — F1's contract is that no submission call ever
 * writes it, because the operator's "I filed it" claim and the provider's `HasResponse` are exactly
 * the two things that must not be mistaken for each other. A provider signal of that kind reaches
 * the aggregate through the recorder, never through this outcome.
 */
final readonly class SubmissionOutcome
{
    public function __construct(public SubmissionState $state)
    {
        in_array($state, [SubmissionState::Draft, SubmissionState::UploadPending, SubmissionState::Submitted], true)
            || throw new InvalidArgumentException(sprintf(
                'A submission reports DRAFT, UPLOAD_PENDING or SUBMITTED, and "%s" is none of '
                . 'them: a call that has just returned has neither submitted nothing (%s) nor been '
                . 'acknowledged by the provider (%s), which arrives as a provider signal.',
                $state->value,
                SubmissionState::None->value,
                SubmissionState::Confirmed->value,
            ));
    }
}
