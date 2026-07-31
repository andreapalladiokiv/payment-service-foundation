<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Subscription\Port;

use Techork\PaymentService\Domain\Subscription\Port\Request\SubscriptionCaptureRequest;

/**
 * Driven port through which a subscription takes the first period's money:
 * capture the authorized payment intent it is being activated with.
 *
 * The subscription declares its own port rather than borrowing the payment
 * intent's or the checkout's, for the reason each aggregate declares its own
 * firewall port — it is typed to what this aggregate holds
 * ({@see SubscriptionCaptureRequest}), and nothing more.
 *
 * Returns nothing, and there is no outcome type to inspect, because capture has
 * no business failure mode: the money was reserved when the intent was
 * authorized. Either it returned and the money moved, or it threw and something
 * infrastructural went wrong, in which case the caller retries the same call.
 * {@see \Techork\PaymentService\Domain\Subscription\SubscriptionAggregate::activate}
 * records nothing on that path, so the subscription stays in the status it
 * already had and the retry starts from the same place.
 *
 * Two obligations are not the host's to choose:
 *
 * 1. Capture through
 *    {@see \Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate::capture},
 *    not straight at the gateway. Its `Authorized`-only check is what stops one
 *    intent activating two subscriptions, and a port that bypasses it satisfies
 *    this interface while losing the guarantee.
 * 2. Commit the intent's `PaymentIntentCaptured` together with
 *    `SubscriptionActivated`, since a captured intent with no activated
 *    subscription is a charged customer with nothing to show for it.
 *
 * What this does NOT buy: serialization of two concurrent activations. Both read
 * `Authorized` from their own hydration and the gateway call precedes the event
 * append, so the aggregate guard rejects the second set of bookkeeping after the
 * money has already left twice. Closing that belongs to the adapter — the
 * gateway's own idempotency key, keyed on the intent.
 */
interface SubscriptionCapturePort
{
    public function capture(SubscriptionCaptureRequest $request): void;
}
