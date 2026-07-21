<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

/**
 * Which point in the flow a risk assessment is being made at. The card is
 * screened twice:
 *
 *  - {@see Registration}  when the payment method is stored, with a zero
 *                         amount. A risky result here forces 3DS at
 *                         authorization and skips the second screening.
 *  - {@see Authorization} at authorize time, with the real amount. Its result
 *                         plus the rules decide whether 3DS runs or is skipped.
 */
enum RiskPhase: string
{
    case Registration = 'registration';
    case Authorization = 'authorization';
}
