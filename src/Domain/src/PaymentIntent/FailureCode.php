<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

use Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentFailed;

/**
 * Why a payment failed, in terms a caller can branch on.
 *
 * {@see PaymentIntentFailed::$reason} stays and stays free text — it is the sentence an operator
 * reads, carrying a rule identifier or an acquirer's own words. This is the part a program is
 * allowed to look at. Without it a merchant integrating against us has one way to tell "do 3DS
 * and try again" from "the issuer said no, stop": matching our prose. That prose is written for
 * humans, gets edited, and is sometimes the acquirer's rather than ours — so the first time we
 * improve a sentence, someone's retry logic changes meaning.
 *
 * The cases answer one question, which is the only question a caller has: is there something to
 * do about this, and what.
 */
enum FailureCode: string
{
    /**
     * Authentication is required and none was presented. Do it, then send the payment again with
     * the result.
     *
     * The one code that describes a payment worth retrying unchanged apart from the evidence. It
     * exists because a firewall chain can demand a step-up on a server-to-server call where there
     * is no cardholder session to conduct one in — no browser to fingerprint, nothing to render
     * an ACS page into. Holding the payment open for an authentication that cannot begin would
     * park it forever, so it is refused now, and the refusal says what would make it succeed.
     */
    case AuthenticationRequired = 'authentication_required';

    /**
     * Authentication happened and did not succeed, or what was presented as authentication did
     * not hold up.
     *
     * Distinct from {@see AuthenticationRequired} because retrying with the same evidence is
     * pointless and retrying without it is worse. Covers an issuer's rejection and a result that
     * failed verification — the caller cannot tell those apart and should not act differently.
     */
    case AuthenticationFailed = 'authentication_failed';

    /**
     * A risk rule refused the payment. Not a question about the cardholder, so no authentication
     * answers it, and nothing about this attempt is worth repeating.
     */
    case Blocked = 'blocked';

    /**
     * The acquirer refused it. Whether that is worth retrying is between the merchant and the
     * decline text, which is what `reason` carries.
     */
    case GatewayDeclined = 'gateway_declined';

    /**
     * Recorded before this enum existed.
     *
     * Only ever read back, never written. It exists so replaying a stream older than the field
     * produces something rather than failing, and so a reader can tell "we did not classify this"
     * from any claim about what happened.
     */
    case Unspecified = 'unspecified';
}
