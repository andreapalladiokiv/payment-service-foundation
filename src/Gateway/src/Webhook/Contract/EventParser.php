<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

/**
 * Turns a raw webhook payload (already parsed into an array by the host
 * framework) into a framework-agnostic {@see ParsedEvent}. One implementation
 * per gateway kind.
 */
interface EventParser
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): ParsedEvent;
}
