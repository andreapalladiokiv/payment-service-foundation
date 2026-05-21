<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Common\Contract\Challenge;

/**
 * Implemented by Omnipay responses that can surface a challenge (3DS step-up,
 * hosted-page redirect, etc.) the client must complete before the transaction
 * can resolve.
 */
interface ChallengeProvider
{
    public function getChallenge(): ?Challenge;
}
