<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\ValueObject;

/**
 * When a cancellation is meant to bite.
 *
 * The subscription knows whether there is a paid-for period to live out; it does not know
 * whether the caller believes that period was ever paid for. A signup whose first payment
 * was refused at capture is activated on paper and owed nothing, and letting it run to the
 * end of a period nobody paid for is the failure this exists to prevent.
 *
 * So the intent comes in with the command and the instant is computed from it — see
 * {@see \Techork\PaymentService\Domain\Subscription\SubscriptionAggregate::cancel}.
 */
enum CancellationTiming: string
{
    /** Over now. The period, if any, is not lived out. */
    case Immediately = 'immediately';

    /**
     * Over when the current period runs out, which is what a subscriber who cancels has
     * already paid for. Falls back to immediate when there is no period — a subscription
     * cancelled before activation has nothing to wait for.
     */
    case AtPeriodEnd = 'at_period_end';
}
