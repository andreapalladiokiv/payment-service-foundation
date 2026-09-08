<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Placing a payment that belongs to a rebilling series.
 *
 * Its own role rather than a third method on {@see PlacesPayments}, because its client is its own
 * — `RebillingCreateAdapter`, backing the subscription aggregate — and because calling it at
 * all is what says "part of a series". No field can say that: a subscription opened by a present
 * cardholder is indistinguishable from a standalone checkout, both being cardholder-initiated
 * with nothing before them.
 *
 * Authorize-only by domain condition rather than preference: `SubscriptionAggregate::activate`
 * requires an `Authorized` intent and captures it, and that split is what makes "one payment
 * intent activates at most one subscription" true without a rule of its own. So there is no
 * charge counterpart here; capture follows through {@see CapturesPayments}.
 */
interface PlacesRebillingPayments
{
    /**
     * @throws UnsupportedByGateway
     */
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult;
}
