<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\DisputeCaseQuery;
use Techork\PaymentService\Gateway\Contract\DisputeCaseReading;

/**
 * Asking the provider that holds a case what is still open on it.
 *
 * The read exists as its own role rather than as a step inside the two acting operations because
 * the question is asked on its own: a caller assembling what to show an operator asks what can
 * still be done without doing any of it, and the answer has to come from the provider that holds
 * the case — a static flag in an adapter would answer for the provider rather than about this case.
 * Nuvei states it per case in `availableActions[]`, where one case offers nothing and its
 * neighbour offers a response.
 *
 * ## Why a provider with no read is not a provider with an empty answer
 *
 * A driver whose product has no dispute API at all — Paynet, Revolut — has no implementation of
 * this role, and that absence is the statement: asking it would be a wiring error, and a driver
 * that answered "nothing" would be saying that about every case it has ever had. What the caller
 * does with a missing binding is its own decision, and it must be able to tell the two apart.
 *
 * A provider that cannot be asked *about this case* is the other side of the same coin, and it is
 * answered rather than refused: ConnexPay's cases come from a read-only API, and one of them that
 * is waiting on us is a case an operator still has to answer. That action is described rather than
 * performed — see the domain's action model — and the fact that it cannot be dispatched from here
 * does not make the case closed.
 */
interface ReadsDisputeCases
{
    /**
     * What the provider says about the case right now.
     *
     * A read, not a promise: the case can move between this answer and any call it suggests, by a
     * deadline passing or by the network deciding, which is why the operations report their own
     * outcome rather than trusting the reading they were built from.
     *
     * @throws \Techork\PaymentService\Gateway\Exception\UnsupportedByGateway when the provider has
     *   no way to read a case at all
     */
    public function readDisputeCase(DisputeCaseQuery $query): DisputeCaseReading;
}
