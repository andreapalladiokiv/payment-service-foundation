<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

it('creates a succeeded outcome', function () {
    $result = GatewayResult::succeeded('ref-123');

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('ref-123')
        ->and($result->message)->toBeNull();
});

it('creates a failed outcome', function () {
    $result = GatewayResult::failed('Card declined');

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->message)->toBe('Card declined');
});

it('exposes only the lean transactional shape — no challenge / customer / checks fields', function () {
    $reflection = new ReflectionClass(GatewayResult::class);
    $properties = array_map(static fn (ReflectionProperty $p): string => $p->getName(), $reflection->getProperties());

    // `metadata` is deliberately part of the base shape: capture / charge
    // responses can carry gateway-specific attributes (e.g. ConnexPay's
    // incoming transaction code) that must travel with the reference.
    // `convertedAmount` likewise: the FX-settled figure a gateway may report
    // on capture / charge (see ConvertedAmountProvider).
    expect($properties)->toBe(['success', 'reference', 'message', 'metadata', 'convertedAmount']);
});

it('defaults metadata to empty and carries it through withMetadata', function () {
    $result = GatewayResult::succeeded('ref-1');

    expect($result->metadata)->toBe([]);

    $withMeta = $result->withMetadata(['incoming_transaction_code' => 'ICT-1']);

    expect($withMeta->metadata)->toBe(['incoming_transaction_code' => 'ICT-1'])
        ->and($withMeta->reference)->toBe('ref-1')
        ->and($withMeta->success)->toBeTrue();
});

it('defaults convertedAmount to null and carries it through withConvertedAmount', function () {
    $result = GatewayResult::succeeded('ref-1');

    expect($result->convertedAmount)->toBeNull();

    $converted = new Money(5712, new Currency('USD'));
    $withConverted = $result->withConvertedAmount($converted);

    expect($withConverted->convertedAmount)->toBe($converted)
        ->and($withConverted->reference)->toBe('ref-1')
        ->and($withConverted->success)->toBeTrue();
});

it('preserves convertedAmount across withMetadata and vice versa', function () {
    $converted = new Money(5712, new Currency('USD'));

    $result = GatewayResult::succeeded('ref-1')
        ->withConvertedAmount($converted)
        ->withMetadata(['incoming_transaction_code' => 'ICT-1']);

    expect($result->convertedAmount)->toBe($converted)
        ->and($result->metadata)->toBe(['incoming_transaction_code' => 'ICT-1']);
});
