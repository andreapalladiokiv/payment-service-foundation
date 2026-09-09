<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\IpAddress;
use Techork\PaymentService\Forter\FraudScreeningRequest;
use Techork\PaymentService\Forter\ForterHttpClientInterface;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;

/**
 * A FraudScreeningRequest with sensible defaults for Forter tests.
 *
 * `$withOptionalBilling` now decides whether the *identity* carries an email rather than whether
 * the address does. The parameter keeps its name because what it controls is unchanged from a
 * caller's point of view — whether Forter's `accountOwner.email` is populated — and where that
 * value lives is the whole subject of the split.
 */
function makeForterScreeningRequest(int $amountMinorUnits = 12345, string $currency = 'USD', bool $withOptionalBilling = true): FraudScreeningRequest
{
    return new FraudScreeningRequest(
        reference: 'fraud-ref-1',
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(6, 2030), new Holder('John Doe')),
        customer: forterSuiteCustomer(
            firstName: 'John',
            lastName: 'Doe',
            email: $withOptionalBilling ? new Email('john@example.com') : null,
        ),
        amountMinorUnits: $amountMinorUnits,
        currencyCode: $currency,
        connection: new ConnectionContext(new IpAddress('203.0.113.7'), 'Mozilla/5.0'),
    );
}

/**
 * A fake Forter HTTP client returning a canned response (or throwing).
 *
 * @param  array<string, mixed>  $response
 */
function fakeForterClient(array $response = [], ?Throwable $throws = null): ForterHttpClientInterface
{
    return new readonly class($response, $throws) implements ForterHttpClientInterface
    {
        /** @param array<string, mixed> $response */
        public function __construct(private array $response, private ?Throwable $throws) {}

        public function postOrder(string $orderId, array $body): array
        {
            if ($this->throws !== null) {
                throw $this->throws;
            }

            return $this->response;
        }
    };
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
function forterSuiteCustomer(
    ?CustomerId $id = null,
    string $firstName = 'Ada',
    string $lastName = 'Lovelace',
    ?Email $email = null,
    ?PhoneNumber $phone = null,
    ?BillingAddress $address = null,
): Customer {
    return new Customer(
        id: $id ?? CustomerId::fromString('01920000-0000-7000-8000-00000000cafe'),
        identity: new CustomerIdentity($firstName, $lastName, $email, $phone),
        billingAddress: $address ?? new BillingAddress(
            line: '1 Main St',
            city: 'New York',
            country: new Country('US'),
            postalCode: '10001',
        ),
    );
}
