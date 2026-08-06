<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Challenge;

use Override;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeVisitor;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;

/**
 * An EMV 3DS authentication the cardholder's browser has to take a step in.
 *
 * Three fields, and the reason there are not more is worth stating, because the obvious model is
 * wrong. 3DS 2.x has two such steps — the 3DS Method, where a hidden frame posts device
 * fingerprinting data before the issuer decides, and the challenge proper, where a visible frame
 * posts a CReq and the cardholder answers. It is tempting to give each its own pair of fields, or
 * its own type. Both are mistakes:
 *
 *  - the sources do not distinguish them. ConnexPay hands back the ACS's `3ds-method` endpoint in
 *    the same field it later uses for the challenge endpoint; the payload differs, the field does
 *    not. Inventing two where the wire has one means guessing which is which.
 *  - which step is in progress is a fact about a conversation between the cardholder's browser
 *    and the ACS. Modelling it here would put that conversation's choreography inside a payments
 *    package, where nothing acts on it.
 *
 * So: post `payload` to `url`. What that means at this moment is the consuming application's
 * business, and it is the one thing an application is certain to know, since it is the party
 * holding the conversation.
 *
 * `$authenticationId` is the protocol's own identity for the authentication — the
 * `threeDSServerTransID`, the value that ties the method step, the authentication response, the
 * challenge and the final result together, and the same value at every vendor because it is a
 * field of the standard rather than of an API. It is what gets recorded, what a later result is
 * matched against, and what a client polls.
 *
 * `$url` is required and `$payload` is not, which is the asymmetry the wire actually has: a step
 * is always somewhere to send the cardholder, and only sometimes something to send with them.
 * Requiring the url also makes an unactionable challenge unconstructible — no adapter can hand
 * back a step-up with nowhere to go and leave a payment held against it. An integration whose
 * step is driven entirely inside a vendor SDK, with no address the browser is given, cannot be
 * expressed here and should refuse rather than approximate. Both are what the far side said to do
 * next, and both expire in minutes.
 *
 * `$protocolVersion` keeps company with the id rather than with the url: it does not expire, it
 * describes the authentication itself, and the result that eventually arrives carries one too
 * ({@see \Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult::$version}), so a
 * mismatch between the two is something a reader of the record can notice.
 */
final readonly class ThreeDSChallenge implements Challenge
{
    public function __construct(
        public string $authenticationId,
        public string $url,
        public ?string $payload = null,
        public ThreeDSVersion $protocolVersion = ThreeDSVersion::V220,
    ) {}

    #[Override]
    public function transactionId(): string
    {
        return $this->authenticationId;
    }

    /** @inheritDoc */
    #[Override]
    public function accept(ChallengeVisitor $visitor): mixed
    {
        return $visitor->visitThreeDS($this);
    }
}
