<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook;

use ArrayObject;
use Override;
use Techork\PaymentService\Gateway\Webhook\Contract\EventParser as EventParserContract;
use Techork\PaymentService\Gateway\Webhook\Contract\ParsedEvent;

/**
 * Parses a ConnexPay webhook body into the flat shape the handlers read.
 *
 * ConnexPay documents a delivery as a JSON **array** of events, with the
 * discriminator and the delivery metadata (`id`, `subject`, `eventType`,
 * `eventTime`, `dataVersion`) at the element level and the transaction fields
 * (`guid`, `amount`, `orderNumber`, `processorMessage`, …) nested under `data`
 * — see {@see https://docs.connexpay.com/docs/webhook-samples-for-sales-events}
 * and {@see https://docs.connexpay.com/docs/sale-message}. The `guid` is unique
 * per transaction and doubles as our idempotency key.
 *
 * Reading that body flat is not a near miss, it is the lossy case: `eventType`
 * and `guid` are both null at the top level, so every delivery parsed to the
 * type `''` and the id `''`. The idempotency gate rejects only a null id, so the
 * first delivery is stored with `external_id = ''` and the unique index on
 * `(name, external_id)` then rejects every delivery after it as a duplicate —
 * one sale gets through and the rest are silently dropped. Hence the envelope is
 * unwrapped here, once, rather than in each handler.
 *
 * Both shapes are accepted, and one path covers them:
 *
 *  - the documented array: the element is taken, and `data` is merged into it;
 *  - the flat body, which is what the VCC decisioning page looks like and what
 *    rows already stored in `webhook_calls` carry — nothing to unwrap, nothing
 *    to merge, and the result is byte-for-byte what this parser returned before.
 *
 * The envelope's own keys win a collision, so a top-level `guid` or `eventType`
 * keeps the meaning it has in the flat form.
 *
 * A body carrying more than one event is represented by its first element only:
 * the store holds one payload per call and the router dispatches one handler per
 * delivery, so the others have nowhere to go. Pinned by a test rather than left
 * implicit — see {@see SaleCorrelation} for what a later correlation needs.
 *
 * Documented event types live as constants below; the
 * {@see ConnexPayWebhookSubscriber} registers handlers only for the subset we
 * act on, and anything else falls through to the router as an unknown type and
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
    #[Override]
    public function parse(array $payload): ParsedEvent
    {
        $event = $this->flatten($payload);
        $type = (string) ($event['eventType'] ?? $event['EventType'] ?? '');
        $externalId = (string) ($event['guid'] ?? $event['Guid'] ?? '');

        return new ParsedEvent($type, $externalId, new ArrayObject($event));
    }

    /**
     * One event, flat: the array envelope unwrapped and `data` merged into it.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function flatten(array $payload): array
    {
        // Read the discriminator before `array_is_list()` narrows the type to a
        // list, which would make these string offsets illegal to Psalm — and the
        // order matters the other way round too: a flat body carries its own
        // `eventType` and must never be unwrapped.
        $hasType = isset($payload['eventType']) || isset($payload['EventType']);
        $first = $payload[0] ?? null;

        if (! $hasType && is_array($first) && array_is_list($payload)) {
            $payload = $first;
        }

        $data = $payload['data'] ?? $payload['Data'] ?? null;
        if (! is_array($data)) {
            return $payload;
        }

        // The envelope first, so its own keys survive the merge; `data` fills in
        // everything the envelope does not name, which is every transaction field.
        return array_replace($data, $payload);
    }
}
