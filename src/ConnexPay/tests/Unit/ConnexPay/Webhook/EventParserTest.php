<?php

declare(strict_types=1);

use Techork\PaymentService\ConnexPay\Webhook\EventParser;

/**
 * One event in the shape ConnexPay documents for a delivery: the body is a JSON
 * **array** of events, the discriminator and the delivery metadata sit at the
 * element level, and the transaction fields are nested under `data`.
 *
 * @see https://docs.connexpay.com/docs/webhook-samples-for-sales-events
 *
 * @param  array<string, mixed>  $data
 * @return list<array<string, mixed>>
 */
function connexPayParserEnvelope(string $eventType, array $data, array $envelope = []): array
{
    return [array_replace([
        'id' => '2f4b8b0e-0000-4000-8000-00000000e001',
        'subject' => '00000000-0000-0000-0000-0000000000aa',
        'eventType' => $eventType,
        'eventTime' => '2026-09-11T10:00:00Z',
        'dataVersion' => '1.0',
        'data' => $data,
    ], $envelope)];
}

it('unwraps the documented array envelope and flattens data into the event', function () {
    // The wire shape ConnexPay documents: a list of events, `eventType` on the
    // element, the transaction fields under `data`. Reading it flat is what made
    // every delivery after the first look like a duplicate: `guid` was null at
    // the top level, so the idempotency key came out as '' and the unique index
    // on (name, external_id) rejected everything that followed.
    $parsed = (new EventParser)->parse(connexPayParserEnvelope(EventParser::TYPE_SALE_AUTH_APPROVED, [
        'guid' => 'sale-guid-1',
        'amount' => 25.00,
        'orderNumber' => '01942f6e-1c3a-7b8d-9e4f-ffffffffffff',
        'processorMessage' => 'Approved',
    ]));

    expect($parsed->type)->toBe(EventParser::TYPE_SALE_AUTH_APPROVED)
        ->and($parsed->externalId)->toBe('sale-guid-1')
        // Flattened, so the handlers keep reading the same flat fields they
        // always have and the wire shape stays in one place.
        ->and($parsed->native['guid'])->toBe('sale-guid-1')
        ->and($parsed->native['amount'])->toBe(25.00)
        ->and($parsed->native['orderNumber'])->toBe('01942f6e-1c3a-7b8d-9e4f-ffffffffffff')
        ->and($parsed->native['processorMessage'])->toBe('Approved')
        // The element's own keys survive the merge.
        ->and($parsed->native['eventType'])->toBe(EventParser::TYPE_SALE_AUTH_APPROVED)
        ->and($parsed->native['eventTime'])->toBe('2026-09-11T10:00:00Z')
        ->and($parsed->native['dataVersion'])->toBe('1.0');
});

it('takes the first event when a body carries more than one', function () {
    // The store holds one payload per call and the router dispatches one event,
    // so the first element is the one represented. Pinned so the choice is
    // visible rather than accidental — see the note in EventParser.
    $body = connexPayParserEnvelope(EventParser::TYPE_SALE_AUTH_DECLINED, ['guid' => 'first-guid']);
    $body[] = connexPayParserEnvelope(EventParser::TYPE_SALE_AUTH_VOIDED, ['guid' => 'second-guid'])[0];

    $parsed = (new EventParser)->parse($body);

    expect($parsed->type)->toBe(EventParser::TYPE_SALE_AUTH_DECLINED)
        ->and($parsed->externalId)->toBe('first-guid');
});

it('leaves a flat body exactly as it was, which is what stored rows carry', function () {
    // Backward compatibility, and not only for the VCC-style flat body: rows
    // already in `webhook_calls` hold a flat payload, and a replay of one has to
    // parse the same way it did when it was stored.
    $parsed = (new EventParser)->parse([
        'eventType' => 'sale.card.auth.approved',
        'guid' => 'sale-guid-1',
        'amount' => '10.00',
    ]);

    expect($parsed->type)->toBe('sale.card.auth.approved')
        ->and($parsed->externalId)->toBe('sale-guid-1')
        ->and($parsed->native->getArrayCopy())->toBe([
        'eventType' => 'sale.card.auth.approved',
        'guid' => 'sale-guid-1',
        'amount' => '10.00',
    ]);
});

it('prefers the envelope over the nested data when both name the same key', function () {
    // The envelope declares the delivery; `data` fills in the transaction. So a
    // top-level `guid` keeps the meaning it has in the flat form.
    $parsed = (new EventParser)->parse(connexPayParserEnvelope(
        EventParser::TYPE_SALE_AUTH_APPROVED,
        ['guid' => 'nested-guid'],
        ['guid' => 'envelope-guid'],
    ));

    expect($parsed->externalId)->toBe('envelope-guid')
        ->and($parsed->native['guid'])->toBe('envelope-guid');
});

it('extracts type and guid from a sale auth approved payload', function () {
    // The legacy flat form: what the VCC decisioning page looks like, and what
    // this parser was written against before the sale-message page was read
    // properly. Kept as the compatibility case, not as the documented shape.
    $parsed = (new EventParser)->parse([
        'eventType' => 'sale.card.auth.approved',
        'guid' => 'sale-guid-1',
        'amount' => '10.00',
    ]);

    expect($parsed->type)->toBe('sale.card.auth.approved')
        ->and($parsed->externalId)->toBe('sale-guid-1')
        ->and($parsed->native)->toBeInstanceOf(ArrayObject::class)
        ->and($parsed->native['amount'])->toBe('10.00');
});

it('falls back to PascalCase keys when present', function () {
    $parsed = (new EventParser)->parse([
        'EventType' => 'purchase.card.auth.settled',
        'Guid' => 'card-guid-9',
    ]);

    expect($parsed->type)->toBe('purchase.card.auth.settled')
        ->and($parsed->externalId)->toBe('card-guid-9');
});

it('returns empty strings for unknown payloads (caller routes to no-handler)', function () {
    $parsed = (new EventParser)->parse([]);

    expect($parsed->type)->toBe('')
        ->and($parsed->externalId)->toBe('');
});

it('exposes documented event-type constants', function () {
    expect(EventParser::TYPE_SALE_AUTH_APPROVED)->toBe('sale.card.auth.approved')
        ->and(EventParser::TYPE_SALE_AUTH_DECLINED)->toBe('sale.card.auth.declined')
        ->and(EventParser::TYPE_SALE_AUTH_VOIDED)->toBe('sale.card.auth.voided')
        ->and(EventParser::TYPE_PURCHASE_AUTH_SETTLED)->toBe('purchase.card.auth.settled');
});
