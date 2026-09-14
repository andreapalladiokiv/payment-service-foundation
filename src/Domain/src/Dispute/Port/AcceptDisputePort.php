<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\Port;

use Techork\PaymentService\Domain\Dispute\Port\Request\AcceptDisputeRequest;
use Techork\PaymentService\Domain\Dispute\ValueObject\DashboardDisputeAction;

/**
 * Concedes a case, in whole or in part, where the provider offers a call for it.
 *
 * Stripe's `POST /v1/disputes/:id/close` and Nuvei's `POST …/{dispute-id}/ACCEPT | PARTIAL` are
 * this port; ConnexPay's equivalent is a portal action and is offered by
 * {@see DisputeActionsPort} as a {@see DashboardDisputeAction} instead. **Accepting is
 * irreversible** at both providers — Stripe moves the case to `lost` and there is no call that
 * takes it back — so requiring an explicit operator confirmation before this is called is the
 * application's job. The adapter does not prompt, and it cannot: it holds no policy and no screen.
 *
 * The partial amount, where the provider supports one, rides on the request rather than being
 * decided here: the action carries the ceiling — what is at stake, so an operator can be shown the
 * number — and what we are willing to concede is chosen while making the call.
 *
 * **A provider that cannot honour a partial amount must refuse it rather than accept in full.**
 * ConnexPay's capture has exactly this failure mode documented in the tree — a partial asked for
 * and the whole hold taken silently — and here the price of the same silence is the entire disputed
 * amount conceded on an irreversible call.
 *
 * The outcome reports what the provider did rather than what we asked for
 * ({@see AcceptOutcome}), so the aggregate records a fact; a call that fails outright throws.
 */
interface AcceptDisputePort
{
    public function accept(AcceptDisputeRequest $request): AcceptOutcome;
}
