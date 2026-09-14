<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\DisputeEvidenceCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Filing a case's response, as one thing a caller can depend on.
 *
 * A role rather than a slice of the whole gateway, for the reason {@see CapturesPayments} is one:
 * the port that needs it needs this operation and nothing else, and asking it to hold an
 * eleven-method interface would not tell a reader what it does. It stands outside the
 * {@see AcquiringGateway} composite on purpose — a provider that acquires has no dispute surface
 * from that fact alone, and every driver in the tree would otherwise have to implement the method —
 * and the role is declared here rather than in a provider package because it is the *caller's*
 * side of the seam: the adapter in `Laravel/Port` names this, never `Stripe`.
 *
 * ## What a refusal means, and which one is expected
 *
 * A provider that took the call but not the response — a case the network has already decided —
 * reports it as a failed result: the gateway hands back a value and never throws a business
 * outcome, and it is the adapter that decides what that value means to the case. What must NOT come
 * back as a failed result is a provider with no submission at all: ConnexPay's dispute API is
 * read-only and an operator files the response in their portal, so its driver refuses with an
 * {@see UnsupportedByGateway}-marked exception, which propagates instead of being recorded as a
 * filing that failed.
 */
interface SubmitsDisputeEvidence
{
    /**
     * The reference of the case the provider accepted evidence for, or a failed result carrying
     * the provider's own message.
     *
     * @throws UnsupportedByGateway when the provider has no way to file a response at all
     */
    public function submitEvidence(DisputeEvidenceCommand $command): GatewayResult;
}
