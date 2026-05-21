<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;

/**
 * @template T
 */
interface ChallengeResultVisitor
{
    /**
     * @return T
     */
    public function visitThreeDS(ThreeDSResult $result): mixed;

    /**
     * @return T
     */
    public function visitRedirect(RedirectResult $result): mixed;
}
