<?php

declare(strict_types=1);

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

it('exposes only the lean transactional triple — no challenge / customer / checks fields', function () {
    $reflection = new ReflectionClass(GatewayResult::class);
    $properties = array_map(static fn (ReflectionProperty $p): string => $p->getName(), $reflection->getProperties());

    expect($properties)->toBe(['success', 'reference', 'message']);
});
