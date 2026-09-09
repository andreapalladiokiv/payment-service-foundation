<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;
use Techork\PaymentService\ConnexPay\Authorize;
use Techork\PaymentService\ConnexPay\CreatePaymentMethod;
use Techork\PaymentService\ConnexPay\Purchase;
use Techork\PaymentService\ConnexPay\Refund;
use Techork\PaymentService\ConnexPay\ReturnRetry;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Customer;

/**
 * ConnexPay's two customer fields, which are different things and are wired differently.
 *
 * `CustomerID` is a top-level transaction attribute carrying OUR id — "a secondary identifier in
 * conjunction with OrderNumber", searchable in the portal — accepted on Auth Only, Create Sale and
 * Capture, and absent from the Void and Return request bodies. `Card.Customer` is the nested block
 * from which ConnexPay creates or links a customer object of its own.
 *
 * Reading the first as evidence against the second is the mistake this file is built around: the
 * searchable field was taken as ConnexPay's whole notion of a customer, so `Card.Customer` went
 * unexamined and went on being assembled out of a billing address.
 */
function connexPayCustomerId(): CustomerId
{
    return CustomerId::fromString('01920000-0000-7000-8000-00000000cafe');
}

// ────────────────────────────── CustomerID, on the three endpoints that take it

it('sends our customer id on the endpoints that accept it', function (string $operation) {
    $command = cpPlacement(['instrument' => cpCard(), 'customerId' => connexPayCustomerId()]);

    $payload = $operation === 'authorize'
        ? new Authorize(cpSettings(), $command, cpInfrastructure(), cpHttpClient())->payload()
        : new Purchase(cpSettings(), $command, cpInfrastructure(), cpHttpClient())->payload();

    expect($payload['CustomerID'])->toBe(connexPayCustomerId()->toString());
})->with(['authorize', 'purchase']);

/**
 * Capture separately, because this is where the field was previously emitted by a request nothing
 * could set it on. The request-level test said `CustomerID` was sent and passed; the interface
 * behind it had no customer parameter, so the field was absent in production for as long as it
 * existed. The command carries it now, which is what makes the assertion mean something.
 */
it('sends our customer id on a capture', function () {
    expect(cpCapture('pi-1:capture', null, connexPayCustomerId())->payload()['CustomerID'])
        ->toBe(connexPayCustomerId()->toString());
});

it('omits the field entirely when no customer was named', function () {
    $command = cpPlacement(['instrument' => cpCard()]);

    expect(new Authorize(cpSettings(), $command, cpInfrastructure(), cpHttpClient())->payload())
        ->not->toHaveKey('CustomerID')
        ->and(cpCapture('pi-1:capture')->payload())->not->toHaveKey('CustomerID');
});

/**
 * Void and Return do not list `CustomerID` in their request bodies, so it must not reach them —
 * which is why it is applied by hand rather than folded into `withIdentifiers()`, the helper every
 * endpoint shares. Sending an undocumented field is the kind of guess that had this adapter reading
 * response keys ConnexPay never returns.
 */
it('never reaches the endpoints that do not document the field', function () {
    $refund = new RefundCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'sale-guid',
        amount: new Money(1000, new Currency('USD')),
        clientUniqueId: 'refund-1',
        retryInstrument: cpCard(),
        customer: connexPaySuiteCustomer(id: connexPayCustomerId()),
    );

    expect(new Refund(cpSettings(), $refund, cpHttpClient())->payload())->not->toHaveKey('CustomerID')
        ->and(new ReturnRetry(cpSettings(), $refund, cpInfrastructure(), cpHttpClient())->payload())->not->toHaveKey('CustomerID')
        ->and(cpVoid('pi-1:cancel')->payload())->not->toHaveKey('CustomerID');
});

/**
 * A UUID survives the sanitiser, which is the reason `CustomerID` can carry one at all: it permits
 * alphanumerics plus `[._/-]`, unlike `SequenceNumber`, which lists no punctuation and would eat
 * the hyphens.
 */
it('keeps a uuid intact and caps the field at a hundred characters', function () {
    $command = cpPlacement(['instrument' => cpCard(), 'customerId' => connexPayCustomerId()]);
    $sent = new Authorize(cpSettings(), $command, cpInfrastructure(), cpHttpClient())->payload()['CustomerID'];

    expect($sent)->toBe('01920000-0000-7000-8000-00000000cafe')
        ->and(strlen((string) $sent))->toBeLessThanOrEqual(100);
});

// ────────────────────────────── Card.Customer, the block that creates their customer

/**
 * The person comes from the identity and the address from the address, and BOTH have to be there.
 *
 * `Card.Customer` is two payloads in one object: `FirstName`, `LastName`, `Phone` and `Email` are
 * the customer ConnexPay creates or links, returned as `card.customer.guid`; `Address1`,
 * `Address2`, `City`, `State`, `Zip` and `Country` are the AVS payload that makes
 * `addressVerificationCode` come back at all. Substituting an identity for the address would have
 * registered the right person and silently ended address verification, since a `CustomerIdentity`
 * holds no address by design.
 */
it('builds the customer from the identity and the address from the address', function () {
    $payload = new CreatePaymentMethod(
        cpSettings(),
        cpVault([
            'instrument' => cpCard(),
            'customer' => connexPaySuiteCustomer(
                firstName: 'Ada',
                lastName: 'Lovelace',
                email: new Email('ada@example.com'),
                phone: new PhoneNumber('+12025550123'),
                address: cpBilling(),
            ),
        ]),
        cpInfrastructure(),
        cpHttpClient(),
    )->payload();

    $customer = $payload['Card']['Customer'];

    expect($customer['FirstName'])->toBe('Ada')
        ->and($customer['LastName'])->toBe('Lovelace')
        ->and($customer['Email'])->toBe('ada@example.com')
        // The address is still there and still complete. This is the assertion that would have
        // caught an identity replacing it — the AVS fields going missing is invisible in the
        // request and shows up as a verification code that stops arriving.
        ->and($customer['City'])->toBe('NYC')
        ->and($customer['Country'])->toBe('US')
        ->and($customer['Address1'])->not->toBeNull()
        ->and($customer['Zip'])->not->toBeNull();
});

/*
 * Two tests lived here and describe arrangements that no longer exist.
 *
 * `it('falls back to the address for the person when no identity was named')` pinned the last
 * resort: with nobody named, the address answered for the person too. That was the state every
 * ConnexPay customer was created in — the payer was whoever the card happened to be billed to —
 * and it is what the whole split removes. A `Customer` has an identity or does not exist, so
 * "no identity was named" is no longer expressible.
 *
 * `it('sends the customer block for an identity with no address at all')` pinned the other half:
 * an identity was worth sending without an address, because it is the part that creates
 * ConnexPay's customer object. An address is required on a customer now, and where it is unknown
 * it is the no-data marker rather than absent — which the test below asserts instead.
 */

/**
 * An unknown address still names the person, and says "no data" for the place.
 *
 * This is what replaced an identity with no address. `BillingAddress::unknown()` is `ZZ` and the
 * shredding stubs, so the block still creates ConnexPay's customer object while the AVS fields
 * carry a value nothing will verify — which is the truth, and is distinguishable from a real
 * address in a way an empty string is not.
 */
it('sends the customer block with the no-data marker for an unknown address', function () {
    $payload = new CreatePaymentMethod(
        cpSettings(),
        cpVault([
            'instrument' => cpCard(),
            'customer' => connexPaySuiteCustomer(
                email: new Email('ada@example.com'),
                address: BillingAddress::unknown(),
            ),
        ]),
        cpInfrastructure(),
        cpHttpClient(),
    )->payload();

    expect($payload['Card'])->toHaveKey('Customer')
        ->and($payload['Card']['Customer']['FirstName'])->toBe('Ada')
        ->and($payload['Card']['Customer']['Country'])->toBe(ShreddingStubs::COUNTRY)
        ->and($payload['Card']['Customer']['City'])->toBe(ShreddingStubs::CITY);
});

it('omits the customer block when no customer was named', function () {
    $payload = new CreatePaymentMethod(
        cpSettings(),
        cpVault(['instrument' => cpCard()]),
        cpInfrastructure(),
        cpHttpClient(),
    )->payload();

    expect($payload['Card'])->not->toHaveKey('Customer');
});

/**
 * A name is transliterated for the same reason the city always was — ConnexPay rejects non-ASCII
 * on this block, "München" and "Kraków" fail validation — and a person's own name is far likelier
 * to carry an accent than anything that survived being typed into an address form. Which is
 * exactly why it had to move with the identity: the old fallback path folded the city and left
 * the name alone.
 */
it('transliterates a name that arrives on the identity', function () {
    $payload = new CreatePaymentMethod(
        cpSettings(),
        cpVault([
            'instrument' => cpCard(),
            'customer' => connexPaySuiteCustomer(firstName: 'Zoë', lastName: 'Kraków'),
        ]),
        cpInfrastructure(),
        cpHttpClient(),
    )->payload();

    expect($payload['Card']['Customer']['FirstName'])->toBe('Zoe')
        ->and($payload['Card']['Customer']['LastName'])->toBe('Krakow');
});
