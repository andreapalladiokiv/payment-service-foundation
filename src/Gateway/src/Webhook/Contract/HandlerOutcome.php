<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

/**
 * What a {@see WebhookEventHandler} reports back to the webhook machinery.
 * Framework-agnostic — the concrete machinery (Laravel jobs, CLI, etc.)
 * interprets these outcomes.
 */
enum HandlerOutcome
{
    /** Event applied successfully. */
    case Processed;

    /** Event is obsolete or duplicate for the current state; no work to do. */
    case Skipped;

    /** Prerequisite not ready — caller should retry the webhook later. */
    case Delay;
}
