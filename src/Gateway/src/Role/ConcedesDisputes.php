<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

use Techork\PaymentService\Gateway\Command\DisputeConcessionCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;

/**
 * Giving up a case, as one thing a caller can depend on.
 *
 * Outside the {@see AcquiringGateway} composite for the reason {@see SubmitsDisputeEvidence} is:
 * acquiring a payment does not entail a dispute to concede, and a driver with no dispute API must
 * not be made to write a method for one it can never receive.
 *
 * ## Irreversible, and the one thing this signature promises
 *
 * There is no call that takes a concession back — the case is lost, the money is gone — so the
 * layer above owes an operator's explicit confirmation before dispatching, and this side owes the
 * narrower promise that nothing is conceded beyond what was asked. A provider that cannot honour a
 * partial amount must refuse the whole call rather than close the case in full; where it has no
 * concession call at all it refuses with an {@see UnsupportedByGateway}-marked exception, which
 * propagates instead of being recorded as a case that was conceded by nobody.
 */
interface ConcedesDisputes
{
    /**
     * The reference of the case the provider closed. A case that was already closed before the
     * call is not a success and not an ordinary failure either — it is reported by the layer that
     * read the case, from its own outcome, and this method must not be used to discover it: a
     * provider that answers "nothing changed here" as a success would let the application record a
     * concession at a moment we did not make one.
     *
     * @throws UnsupportedByGateway when the provider has no concession call, or cannot concede part
     */
    public function concede(DisputeConcessionCommand $command): GatewayResult;
}
