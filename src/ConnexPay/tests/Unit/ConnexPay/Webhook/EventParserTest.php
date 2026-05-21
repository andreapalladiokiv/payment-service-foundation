<?php

declare(strict_types=1);

use Techork\PaymentService\ConnexPay\Webhook\EventParser;

it('extracts type and guid from a sale auth approved payload', function () {
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
