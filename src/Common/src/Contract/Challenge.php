<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

/**
 * Interim state — the gateway has handed off to an external system (issuer ACS,
 * hosted payment page, etc.) and the cardholder's browser must complete an
 * action before the transaction can resolve. Concrete implementations carry
 * the parameters the client needs to render the action.
 *
 * Polymorphism via {@see ChallengeVisitor}: consumers dispatch on the concrete
 * type to produce transport-specific shapes (event payload, API response).
 */
interface Challenge
{
    public function transactionId(): string;

    /**
     * @template T
     *
     * @param  ChallengeVisitor<T>  $visitor
     * @return T
     */
    public function accept(ChallengeVisitor $visitor): mixed;
}
