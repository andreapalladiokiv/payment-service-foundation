<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook\Contract;

/**
 * Gateway-agnostic representation of a parsed webhook event. The router uses
 * `type` to resolve a handler; handlers receive `native` (a gateway-native
 * object, e.g. Stripe\Event or a Nuvei DTO).
 *
 * `externalId` is the idempotency key for this delivery: Stripe's `event.id`,
 * or a synthesized `transactionType:PPP_TransactionID` for Nuvei.
 *
 * @template T
 */
final readonly class ParsedEvent
{
    /**
     * @param  T  $native
     */
    public function __construct(
        public string $type,
        public string $externalId,
        public object $native,
    ) {}
}
