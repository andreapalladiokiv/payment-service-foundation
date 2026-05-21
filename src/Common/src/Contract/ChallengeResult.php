<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

/**
 * Terminal artefact of a completed {@see Challenge}. Sealed via the
 * visitor pattern: each concrete challenge type defines its own result
 * shape and dispatches through {@see ChallengeResultVisitor}.
 */
interface ChallengeResult
{
    /**
     * @template T
     *
     * @param  ChallengeResultVisitor<T>  $visitor
     * @return T
     */
    public function accept(ChallengeResultVisitor $visitor): mixed;
}
