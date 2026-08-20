<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;

it('round-trips through its array form', function (CustomerIdentity $identity) {
    expect(CustomerIdentity::fromArray($identity->toArray()))->toEqual($identity);
})->with([
    'everything' => [fn () => new CustomerIdentity('Ada', 'Lovelace', new Email('ada@example.com'), new PhoneNumber('+12025550142'))],
    'no email' => [fn () => new CustomerIdentity('Ada', 'Lovelace', null, new PhoneNumber('+12025550142'))],
    'no phone' => [fn () => new CustomerIdentity('Ada', 'Lovelace', new Email('ada@example.com'))],
    'name only' => [fn () => new CustomerIdentity('Ada', 'Lovelace')],
]);

/**
 * The one field that must stay optional. A missing email currently decides whether a customer
 * exists at all — it is the identity at Nuvei and was the gate on creating one at Stripe — so
 * a type that required it would build that back in.
 */
it('is constructible with a name and nothing else', function () {
    $identity = new CustomerIdentity('Ada', 'Lovelace');

    expect($identity->email)->toBeNull()
        ->and($identity->phone)->toBeNull();
});

/**
 * "We deleted this" has to read the same as "we never had this", which is what the stubs are
 * for: nothing downstream should be able to tell an erased customer from an unknown one, and
 * neither should be mistaken for a real person.
 */
it('reads as the shredding stubs once forgotten', function () {
    $forgotten = CustomerIdentity::forgotten();

    expect($forgotten->firstName)->toBe(ShreddingStubs::NAME)
        ->and($forgotten->lastName)->toBe(ShreddingStubs::NAME)
        ->and($forgotten->email)->toBeNull()
        ->and($forgotten->phone)->toBeNull()
        ->and(CustomerIdentity::fromArray($forgotten->toArray()))->toEqual($forgotten);
});

/**
 * The reading a backfill depends on. Until now the payer's name lived on the address — one copy
 * per card — so a payment method nobody ever named a customer for still knows who paid with it,
 * and that is the only reason every existing payment method can be given an owner.
 */
it('reads an identity out of a billing address, and stubs stay stubs', function () {
    $identity = CustomerIdentity::fromBillingAddress(new BillingAddress(
        'Ada',
        'Lovelace',
        '1 Analytical St',
        'London',
        new Country('GB'),
        'W1A 1AA',
        email: new Email('ada@example.test'),
        phone: new PhoneNumber('+442079460958'),
    ));

    expect($identity->firstName)->toBe('Ada')
        ->and($identity->lastName)->toBe('Lovelace')
        ->and((string) $identity->email)->toBe('ada@example.test')
        ->and((string) $identity->phone)->toBe('+442079460958');

    // An address we never had yields an identity we never had, rather than being refused —
    // `ShreddingStubs::NAME` is a name here for the same reason it is one in `forgotten()`.
    $unknown = CustomerIdentity::fromBillingAddress(BillingAddress::unknown());

    expect($unknown->firstName)->toBe(ShreddingStubs::NAME)
        ->and($unknown->email)->toBeNull();
});
