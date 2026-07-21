<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Domain\PaymentIntent\Port\Request\RiskAssessmentRequest;

/**
 * Driven port the PaymentIntent flow consults to decide how a card
 * transaction should be authenticated. The implementation (in the consuming
 * application) runs fraud screening, enriches BIN/IP, applies the
 * operator-configured rule engine, and returns the {@see RiskAction} to
 * enforce — translated by the aggregate into the existing 3DS challenge
 * mechanism.
 *
 * Consulted at both {@see RiskPhase::Registration} and
 * {@see RiskPhase::Authorization}. Implementations MUST NOT throw for a
 * business outcome and MUST encapsulate the fail-open / fail-closed policy;
 * callers always receive a {@see RiskOutcome} and never have to block on
 * provider failure themselves.
 */
interface RiskDecisionPort
{
    public function decide(RiskAssessmentRequest $request): RiskOutcome;
}
