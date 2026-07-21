<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

/**
 * The action the payment flow must take for a card transaction, as decided by
 * the {@see RiskDecisionPort} from the fraud verdict plus operator-configured
 * rules. Fraud risk never blocks a payment on its own — it routes to stronger
 * authentication.
 *
 *  - {@see Require3ds} perform 3DS step-up before authorizing.
 *  - {@see Skip3ds}    proceed to authorize without 3DS (granted only to
 *                      low-risk, trusted-BIN transactions per the rules).
 *  - {@see Allow}      proceed with no risk-driven step-up (e.g. screening
 *                      not applicable). 3DS may still run for other reasons.
 */
enum RiskAction: string
{
    case Require3ds = 'require_3ds';
    case Skip3ds = 'skip_3ds';
    case Allow = 'allow';
}
