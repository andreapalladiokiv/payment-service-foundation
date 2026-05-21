<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;

/**
 * @template T
 */
interface ChallengeVisitor
{
    /**
     * @return T
     */
    public function visitThreeDS(ThreeDSChallenge $challenge): mixed;

    /**
     * @return T
     */
    public function visitRedirect(RedirectChallenge $challenge): mixed;
}
