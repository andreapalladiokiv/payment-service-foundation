<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;
use Techork\PaymentService\Common\ValueObject\State;

function makeCustomerFixture(?BillingAddress $address = null): Customer
{
    return new Customer(
        id: CustomerId::fromString('01920000-0000-7000-8000-00000000cafe'),
        identity: new CustomerIdentity('Ada', 'Lovelace', new Email('ada@example.com'), new PhoneNumber('+12025550142')),
        billingAddress: $address ?? new BillingAddress(
            line: '1 Analytical Way',
            city: 'Juneau',
            country: new Country('US'),
            postalCode: '99801',
            state: new State('AK'),
        ),
    );
}

it('exposes the three parts of a payer', function () {
    $customer = makeCustomerFixture();

    expect($customer->id)->toBeInstanceOf(CustomerId::class)
        ->and($customer->identity->firstName)->toBe('Ada')
        ->and($customer->billingAddress->city)->toBe('Juneau');
});

/**
 * The completeness is the invariant, and it is what the type exists for.
 *
 * The id, the identity and the address were three separately-omissible things — two nullable
 * arguments on a gateway command beside an optional id — so a provider-side customer got
 * assembled out of whatever subset was to hand, which in practice meant the billing address that
 * rode along with the payment. There is no partial customer to reach for now: a caller with no
 * payer to name passes no customer.
 */
it('cannot be constructed without every part', function () {
    $arguments = new ReflectionClass(Customer::class)->getConstructor()?->getParameters() ?? [];

    expect($arguments)->toHaveCount(3);

    foreach ($arguments as $argument) {
        expect($argument->isOptional())->toBeFalse()
            ->and($argument->getType()?->allowsNull())->toBeFalse();
    }
});

/**
 * The three stay three, rather than being flattened, because they are not interchangeable.
 * ConnexPay's `Card.Customer` is the case that proves it: four person fields and six AVS fields
 * in one object, so an identity substituted for an address registers the right person and
 * silently ends address verification.
 */
it('keeps the person and the place apart in its array form', function () {
    expect(array_keys(makeCustomerFixture()->toArray()))->toBe(['id', 'identity', 'billing_address'])
        ->and(array_keys(makeCustomerFixture()->toArray()['identity']))
        ->toBe(['first_name', 'last_name', 'email', 'phone'])
        ->and(array_keys(makeCustomerFixture()->toArray()['billing_address']))
        ->toBe(['line', 'line_extra', 'city', 'country', 'postal_code', 'state']);
});

it('round-trips through its array form', function () {
    $customer = makeCustomerFixture();

    expect(Customer::fromArray($customer->toArray()))->toEqual($customer);
});

/**
 * The array form is not the shape the event stream uses, and this is where that is easiest to
 * forget. `BillingAddress::toArray()` writes a state as its code alone, so a `State` built with
 * a country comes back without one — the exact mismatch
 * {@see \Techork\PaymentService\Laravel\Serializer\StateNormalizer} exists for on the event
 * path, which normalizes the object graph rather than calling `toArray()`. Pinned here so the
 * limitation is a known one rather than a surprise for a caller that persists a customer itself.
 */
it('loses a state country through its array form, as the address always has', function () {
    $customer = makeCustomerFixture(new BillingAddress(
        line: '1 Analytical Way',
        city: 'Juneau',
        country: new Country('US'),
        postalCode: '99801',
        state: new State('AK', new Country('US')),
    ));

    expect((string) Customer::fromArray($customer->toArray())->billingAddress->state)->toBe('AK')
        ->and(Customer::fromArray($customer->toArray())->billingAddress->state?->getCountry())->toBeNull();
});

/**
 * An unknown address is `unknown()`, not null — the marker that says "no data", which is what a
 * GDPR-erased row carries too. Saying it this way keeps every mapper on one code path and keeps
 * `ZZ` out of AVS as a fact.
 */
it('carries an unknown address as the no-data marker', function () {
    $customer = makeCustomerFixture(BillingAddress::unknown());

    expect($customer->billingAddress->city)->toBe(ShreddingStubs::CITY)
        ->and((string) $customer->billingAddress->country)->toBe(ShreddingStubs::COUNTRY)
        ->and(Customer::fromArray($customer->toArray()))->toEqual($customer);
});

/*
 * `it('rebuilds a stored id as a CustomerId whatever shape went in')` lived here.
 *
 * It pinned the one narrowing `fromArray()` made while the id was a `CustomerIdentifier`
 * interface: an application could hand its own implementation across a boundary, and reading a
 * stored customer back could only rebuild the shape this package knows. The interface is gone and
 * `Customer::$id` is a `CustomerId`, so there is no second shape to narrow from — an application
 * keyed on something else maps to one of these at its own edge, before a `Customer` exists.
 */
