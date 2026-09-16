<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;

/**
 * A card, real rather than doubled, for the tests where the decorator has to read one.
 *
 * The decorator projects an instrument through {@see \Techork\PaymentService\Common\ValueObject\
 * CreditCard\CardSummaryExtractor}, which dispatches on the concrete type by calling `accept()`.
 * A Mockery mock is the wrong stand-in now: Mockery refuses an unstubbed call, and stubbing it
 * would assert that the visitor was called rather than what it found.
 */
function gatewaySuiteCard(
    string $first6 = '411111',
    string $last4 = '1111',
    string $holder = 'Ada Lovelace',
): CreditCard {
    return new CreditCard(
        number: new Number($first6, $last4, CardBrand::Visa),
        expiration: new Expiration(new DateTimeImmutable('2030-12-01')),
        holder: new Holder($holder),
        cvc: new Cvc,
    );
}

/**
 * A customer id for this suite's tests.
 */
function gatewaySuiteCustomerId(string $id = '01920000-0000-7000-8000-00000000cafe'): CustomerId
{
    return CustomerId::fromString($id);
}

/**
 * The payer these tests hand to a command, complete, because a {@see Customer} has no partial
 * form — an id, a person and an address or nothing at all.
 *
 * That completeness is the change worth knowing about here. The id, the identity and the address
 * used to be three optional arguments a caller could supply any subset of, which is how a
 * provider-side customer came to be built out of whatever billing address rode along with the
 * payment. A test that wants to say "no payer" passes null, not a fragment.
 */
function gatewaySuiteCustomer(
    ?CustomerId $id = null,
    string $firstName = 'Ada',
    string $lastName = 'Lovelace',
    ?Email $email = null,
    ?PhoneNumber $phone = null,
    ?BillingAddress $address = null,
): Customer {
    return new Customer(
        id: $id ?? gatewaySuiteCustomerId(),
        identity: new CustomerIdentity($firstName, $lastName, $email, $phone),
        billingAddress: $address ?? new BillingAddress(
            line: '1 Main St',
            city: 'New York',
            country: new Country('US'),
            postalCode: '10001',
        ),
    );
}

/**
 * The one customer a command now takes, assembled from the option keys these tests have used all
 * along.
 *
 * `billingAddress`, `customerId` and `customerIdentity` were three separate command fields and
 * are one. The keys stay because what each test is *saying* has not changed — "billed here", "for
 * this customer", "who is this person" — and rewriting every call site to say it a new way would
 * bury the change that matters in the change that does not.
 *
 * Naming any one of them yields a whole customer, which is the design: an address with nobody
 * attached to it is not expressible any more. A test that means "no payer at all" names none of
 * the three and gets null.
 *
 * @param  array<string, mixed>  $options
 */
function gatewaySuiteCustomerFrom(array $options): ?Customer
{
    if (array_key_exists('customer', $options)) {
        return $options['customer'];
    }

    $named = ['billingAddress', 'customerId', 'customerIdentity'];
    if (! array_filter($named, static fn (string $k): bool => ($options[$k] ?? null) !== null)) {
        return null;
    }

    /** @var ?CustomerIdentity $identity */
    $identity = $options['customerIdentity'] ?? null;

    return gatewaySuiteCustomer(
        id: $options['customerId'] ?? null,
        firstName: $identity->firstName ?? 'Ada',
        lastName: $identity->lastName ?? 'Lovelace',
        email: $identity->email ?? null,
        phone: $identity->phone ?? null,
        address: $options['billingAddress'] ?? null,
    );
}
