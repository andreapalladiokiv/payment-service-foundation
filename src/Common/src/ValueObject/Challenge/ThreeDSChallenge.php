<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Challenge;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeVisitor;

/**
 * 3DS step-up challenge. Depending on the integration:
 *  - Direct MPI: acsUrl + creq (browser POSTs creq to acsUrl).
 *  - Gateway SDK (e.g. Stripe.js): acsUrl + clientSecret; creq handled inside
 *    the SDK, invisible to the merchant.
 */
final readonly class ThreeDSChallenge implements Challenge
{
    public function __construct(
        public string $transactionId,
        public ?string $acsUrl = null,
        public ?string $creq = null,
        public ?string $clientSecret = null,
    ) {}

    public function transactionId(): string
    {
        return $this->transactionId;
    }

    public function accept(ChallengeVisitor $visitor): mixed
    {
        return $visitor->visitThreeDS($this);
    }
}
