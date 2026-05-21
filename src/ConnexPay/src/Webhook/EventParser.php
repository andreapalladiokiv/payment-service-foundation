<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook;

use ArrayObject;
use Techork\PaymentService\Gateway\Webhook\Contract\EventParser as EventParserContract;
use Techork\PaymentService\Gateway\Webhook\Contract\ParsedEvent;

/**
 * Parses a ConnexPay webhook body. ConnexPay sends JSON with an
 * `eventType` discriminator (sale/purchase lifecycle, see
 * {@see https://docs.connexpay.com/docs/sale-message}) and a `guid` —
 * unique per transaction, doubles as our idempotency key.
 *
 * Documented event types we react to today live as constants below.
 * Anything else falls through to the router as an unknown type and
 * resolves to {@see HandlerOutcome::Skipped}.
 */
final readonly class EventParser implements EventParserContract
{
    public const string TYPE_SALE_AUTH_APPROVED = 'sale.card.auth.approved';

    public const string TYPE_SALE_AUTH_DECLINED = 'sale.card.auth.declined';

    public const string TYPE_SALE_AUTH_VOIDED = 'sale.card.auth.voided';

    public const string TYPE_PURCHASE_AUTH_SETTLED = 'purchase.card.auth.settled';

    /**
     * @return ParsedEvent<ArrayObject>
     */
    public function parse(array $payload): ParsedEvent
    {
        $type = (string) ($payload['eventType'] ?? $payload['EventType'] ?? '');
        $externalId = (string) ($payload['guid'] ?? $payload['Guid'] ?? '');

        return new ParsedEvent($type, $externalId, new ArrayObject($payload));
    }
}
