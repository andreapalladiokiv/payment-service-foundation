<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\MerchantDescriptor;

it('creates a descriptor from printable ASCII', function () {
    expect((string) new MerchantDescriptor('ACME STORE #17'))->toBe('ACME STORE #17');
});

it('accepts an empty descriptor', function () {
    // 8,092 of the intents on record carry no descriptor and the acquirer falls
    // back to the merchant's configured default. Rejecting empty would make
    // every one of them unimportable.
    $descriptor = new MerchantDescriptor('');

    expect((string) $descriptor)->toBe('')
        ->and($descriptor->isEmpty())->toBeTrue()
        ->and(MerchantDescriptor::none())->toEqual($descriptor);
});

it('accepts a descriptor at the length ceiling', function () {
    expect((string) new MerchantDescriptor(str_repeat('A', 25)))->toBe(str_repeat('A', 25));
});

it('rejects a descriptor past the length ceiling', function () {
    // 25 is ConnexPay's limit and the widest any acquirer we integrate takes.
    // The narrower 22 that most of them enforce is per-gateway and stays in
    // request validation, which knows where the payment is routed.
    new MerchantDescriptor(str_repeat('A', 26));
})->throws(RuntimeException::class, 'exceeds 25 characters');

it('rejects a non-ASCII descriptor', function (string $descriptor) {
    new MerchantDescriptor($descriptor);
})->with([
    'cyrillic' => 'МАГАЗИН',
    'accented' => 'CAFÉ',
    'emoji' => 'STORE 🛒',
    'control character' => "ACME\nSTORE",
    'tab' => "ACME\tSTORE",
])->throws(RuntimeException::class, 'printable ASCII');

it('rejects characters the networks refuse', function (string $descriptor) {
    new MerchantDescriptor($descriptor);
})->with([
    'less than' => 'ACME <STORE',
    'greater than' => 'ACME >STORE',
    'backslash' => 'ACME\\STORE',
    'double quote' => 'ACME "STORE"',
    'single quote' => "ACME'S STORE",
])->throws(RuntimeException::class, 'must not contain');

it('serialises to its string form', function () {
    $descriptor = new MerchantDescriptor('ACME STORE');

    expect($descriptor->jsonSerialize())->toBe('ACME STORE')
        ->and(json_encode($descriptor))->toBe('"ACME STORE"');
});

it('exposes one string property matching its constructor parameter', function () {
    // Not cosmetic: the event store serialises payloads with Symfony's
    // PropertyNormalizer, which emits each private property and then satisfies
    // the constructor from those keys. A single string property named after the
    // parameter round-trips unaided — an inner object would not, which is why
    // PhoneNumber needed a dedicated normalizer to stop every phone-bearing
    // aggregate from failing to reconstitute.
    $properties = new ReflectionClass(MerchantDescriptor::class)->getProperties(ReflectionProperty::IS_PRIVATE);
    $instance = new MerchantDescriptor('ACME STORE');

    $names = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        array_values(array_filter($properties, static fn (ReflectionProperty $p): bool => ! $p->isStatic())),
    );

    expect($names)->toBe(['descriptor'])
        ->and(new ReflectionMethod(MerchantDescriptor::class, '__construct')->getParameters()[0]->getName())
        ->toBe('descriptor');

    // The round-trip PropertyNormalizer actually performs.
    $normalised = ['descriptor' => (fn () => $this->descriptor)->call($instance)];

    expect(new MerchantDescriptor(...$normalised))->toEqual($instance);
});
