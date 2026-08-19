<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\SdkChallenge;
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

    /**
     * The shape with no address: the provider's SDK conducts the step-up in the payer's
     * browser. Adding it here is breaking on purpose — a visitor that has not been taught
     * this case would otherwise silently mis-handle a challenge it has never seen.
     *
     * @return T
     */
    public function visitSdk(SdkChallenge $challenge): mixed;
}
