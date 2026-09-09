<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\State;

/**
 * `RiskData.Name` and `RiskData.Email` come off the customer's identity now, not the address.
 *
 * The two used to be the same thing and the test could not tell them apart: the address held a
 * `firstName`/`lastName`/`email`, so naming the address named the payer. That is the copy the
 * split removes — a payer per card, correctable nowhere. What has NOT changed is which ConnexPay
 * fields they land in, and that is what the assertions still pin.
 */
it('forwards the payer and their address to top-level RiskData (no N/A hardcode)', function () {
    $billing = new BillingAddress(
        line: '456 Oak Ave',
        city: 'Tempe',
        country: new Country('US'),
        postalCode: '85284',
        state: new State('AZ'),
    );

    $data = cpAuthorize([
        'money' => new Money(450, new Currency('USD')),
        'instrument' => cpCard(holder: 'Jane Smith'),
        'customer' => connexPaySuiteCustomer(
            firstName: 'Jane',
            lastName: 'Smith',
            email: new Email('jane@test.com'),
            address: $billing,
        ),
    ])->payload();

    $risk = $data['RiskData'];

    expect($data['Card'])->not->toHaveKey('Customer')
        ->and($risk['Name'])->toBe('Jane Smith')
        ->and($risk['Email'])->toBe('jane@test.com')
        ->and($risk['BillingAddress1'])->toBe('456 Oak Ave')
        ->and($risk['BillingState'])->toBe('AZ')
        ->and($risk['BillingCountryCode'])->toBe('US')
        ->and($risk['BillingPostalCode'])->toBe('85284');
});

it('keeps all RiskData keys present with nulls when optional fields are missing', function () {
    $billing = new BillingAddress(
        line: '1 St',
        city: 'NYC',
        country: new Country('US'),
        postalCode: '10001',
    );

    $risk = cpAuthorize([
        'money' => new Money(100, new Currency('USD')),
        'instrument' => cpCard(cvv: null, holder: 'John Doe'),
        'customer' => connexPaySuiteCustomer(firstName: 'John', lastName: 'Doe', address: $billing),
    ])->payload()['RiskData'];

    expect($risk)->toBe([
        'Name' => 'John Doe',
        'BillingPhoneNumber' => null,
        'BillingState' => null,
        'BillingCountryCode' => 'US',
        'Email' => null,
        'BillingAddress1' => '1 St',
        'BillingAddress2' => '',
        'BillingPostalCode' => '10001',
    ]);
});
