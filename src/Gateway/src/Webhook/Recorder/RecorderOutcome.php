<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Recorder;

/**
 * Outcome of a recorder operation. Framework-agnostic — callers map these to
 * {@see HandlerOutcome} in their own translation.
 */
enum RecorderOutcome
{
    /** The event was applied to the aggregate. */
    case Applied;

    /** Already-processed / no-op for the current state. */
    case Skipped;

    /** Aggregate or dependency not yet visible — caller should retry later. */
    case NotFound;
}
