<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;

/**
 * The payer a payment-intent test needs, complete, because a {@see Customer} has no partial form.
 *
 * One helper rather than a `new Customer(new CustomerId(...), new CustomerIdentity(...), new
 * BillingAddress(...))` at every call site: the aggregate carries the customer through five
 * events and the assertions are about the payment, not about assembling a person. Every argument
 * is defaulted so a test overrides only the part it is making a point about.
 */
function makeCustomer(
    ?CustomerId $id = null,
    string $firstName = 'Ada',
    string $lastName = 'Lovelace',
    ?Email $email = null,
    ?BillingAddress $address = null,
): Customer {
    return new Customer(
        id: $id ?? CustomerId::fromString('01920000-0000-7000-8000-00000000cafe'),
        identity: new CustomerIdentity($firstName, $lastName, $email),
        billingAddress: $address ?? new BillingAddress(
            line: '1 Analytical Way',
            city: 'Juneau',
            country: new Country('US'),
            postalCode: '99801',
        ),
    );
}
