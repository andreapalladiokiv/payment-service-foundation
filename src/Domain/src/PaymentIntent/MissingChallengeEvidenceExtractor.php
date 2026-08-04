<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

use Techork\PaymentService\Common\Contract\ChallengeResultVisitor;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;

/**
 * Says why a {@see \Techork\PaymentService\Common\Contract\ChallengeResult} that claims
 * success does not carry what makes it successful, or null when it does.
 *
 * Separate from {@see ChallengeFailureReasonExtractor}, which answers a different
 * question. That one asks what the issuer said; this one asks whether the artefact is
 * internally coherent. A result can pass the first and fail the second — status `Y` with
 * no authentication value claims a liability shift while carrying no evidence of one —
 * and the two failures must not be treated alike: an incoherent result is nobody's
 * refusal, so recording it as a decline would put words in the issuer's mouth.
 *
 * All three statuses the domain treats as success carry an authentication value: `Y`
 * because the cardholder authenticated, `A` as the proof of the attempt, and `I` as
 * well — the informational answer still comes back with a value and an ECI, which is
 * why it counts as completed rather than skipped. So an empty one is a contradiction
 * rather than a variant.
 *
 * ECI is deliberately not required. Whether it must be present is an acquirer's rule —
 * Nuvei marks it required, ConnexPay settles a sale without it, Stripe exempts Cartes
 * Bancaires — and the adapters enforce their own. This asks only what every reading of
 * the protocol agrees on.
 *
 * @implements ChallengeResultVisitor<?string>
 */
final class MissingChallengeEvidenceExtractor implements ChallengeResultVisitor
{
    public function visitThreeDS(ThreeDSResult $result): ?string
    {
        $claimsSuccess = in_array($result->status, [
            ThreeDSStatus::Successful,
            ThreeDSStatus::NotAvailable,
            ThreeDSStatus::Info,
        ], true);

        if (! $claimsSuccess) {
            // Not this visitor's business: a refusal is answered by the other one, and
            // a refusal with no cryptogram is exactly what a refusal looks like.
            return null;
        }

        return ($result->authenticationValue ?? '') === ''
            ? "3DS authentication reported {$result->status->value} without an authentication value"
            : null;
    }

    public function visitRedirect(RedirectResult $result): ?string
    {
        // Its evidence is the transaction it names, and the type already requires a
        // non-empty one, so there is no incoherent RedirectResult to describe.
        return null;
    }
}
