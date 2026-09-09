<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;

it('is the identifier boundaries speak', function () {
    expect(CustomerId::generate())->toBeInstanceOf(CustomerId::class);
});

it('round-trips through its string form', function () {
    $id = CustomerId::fromString('01920000-0000-7000-8000-00000000cafe');

    expect($id->toString())->toBe('01920000-0000-7000-8000-00000000cafe')
        ->and((string) $id)->toBe('01920000-0000-7000-8000-00000000cafe');
});

it('refuses a value that is not a uuid', function () {
    expect(fn () => CustomerId::fromString('not-a-uuid'))->toThrow(InvalidArgumentException::class);
});

/**
 * Equality is by class as well as by value — `UuidValueObject::equals()` compares
 * `static::class` — so a customer id and a payment method id standing on the same UUID are not
 * the same thing. Worth an assertion because the failure would be silent: two ids that compare
 * equal across types is how a card gets attributed to the wrong record.
 */
it('is equal only to another customer id with the same value', function () {
    $uuid = '01920000-0000-7000-8000-00000000cafe';

    expect(CustomerId::fromString($uuid)->equals(CustomerId::fromString($uuid)))->toBeTrue()
        ->and(CustomerId::fromString($uuid)->equals(CustomerId::generate()))->toBeFalse()
        ->and(CustomerId::fromString($uuid)->equals(PaymentMethodId::fromString($uuid)))->toBeFalse();
});
