<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\ConnexPay\Capture;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\ConnexPaySettings;
use Techork\PaymentService\ConnexPay\VoidTransaction;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;

/*
 * The three shapes every ConnexPay operation is built from, kept apart because they are three
 * different things: the settings are what the deployment configured, the command is what the
 * caller asked for, and the infrastructure is what the process was wired with. They used to
 * arrive as one array — which is why a request had accessors that could not say where a value
 * had come from, and why a test had to spell all three at every call site.
 */

function cpEncrypter(): EncryptInterface
{
    return new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };
}

function cpDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };
}

function cpCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'ConnexPay';
        }

        public function getCredentials(): array
        {
            return [];
        }
    };
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cpSettings(array $overrides = []): ConnexPaySettings
{
    return new ConnexPaySettings(
        deviceGuid: $overrides['deviceGuid'] ?? 'device-1',
        merchantGuid: $overrides['merchantGuid'] ?? 'merchant-1',
        merchantName: $overrides['merchantName'] ?? 'Techork Store',
        accountCurrency: $overrides['accountCurrency'] ?? '',
        environment: $overrides['environment'] ?? 'sandbox',
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cpInfrastructure(array $overrides = []): GatewayInfrastructure
{
    return new GatewayInfrastructure(
        $overrides['credential'] ?? cpCredential(),
        $overrides['decrypter'] ?? cpDecrypter(),
        $overrides['instruments'] ?? Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        $overrides['customers'] ?? Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        $overrides['settings'] ?? [],
    );
}

/**
 * A reference resolver that answers with one guid whatever it is asked about.
 */
function cpInstruments(?string $reference): GatewayInstrumentRepository
{
    $instruments = Mockery::mock(GatewayInstrumentRepository::class);
    $instruments->shouldReceive('find')->andReturn($reference);

    return $instruments;
}

function cpHttpClient(): ConnexPayHttpClientInterface
{
    return Mockery::mock(ConnexPayHttpClientInterface::class);
}

function cpCard(string $number = '4012000098765439', ?string $cvv = '999', string $holder = 'Test User'): CreditCard
{
    return new CreditCard(
        Number::fromNumber($number, cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder($holder),
        $cvv === null ? new Cvc : Cvc::fromCvc($cvv, cpEncrypter()),
    );
}

function cpBilling(string $city = 'NYC'): BillingAddress
{
    return new BillingAddress(
        line: '456 Oak',
        city: $city,
        country: new Country('US'),
        postalCode: '90001',
    );
}

/**
 * The payer these tests bill, address and person together.
 *
 * `cpBilling()` used to take the email, because the address held it. It is the identity's now, so
 * a test that cares about `Customer.Email` — and several do, ConnexPay puts it in the same block
 * as the AVS fields — asks for a payer rather than an address.
 */
function cpPayer(string $city = 'NYC', ?string $email = 'buyer@test.com'): Customer
{
    return connexPaySuiteCustomer(
        email: $email === null ? null : new Email($email),
        address: cpBilling($city),
    );
}

function cpStoredToken(): Token
{
    return new Token(
        TokenId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );
}

function cpStoredPaymentMethod(): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
    );
}

/**
 * The same stored card with a customer attached — the state a payment operation requires.
 *
 * Both fixtures are needed: this one for the payments, cpStoredPaymentMethod() for the tests that
 * assert the refusal. Attached is a state rather than a type, so the difference between
 * them is one constructor argument.
 */
function cpAttachedPaymentMethod(): PaymentMethod
{
    $bare = cpStoredPaymentMethod();

    return new PaymentMethod($bare->id, $bare->instrument, connexPaySuiteCustomer());
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cpPlacement(array $overrides = []): PlacementCommand
{
    return new PlacementCommand(
        gatewayId: $overrides['gatewayId'] ?? GatewayId::generate(),
        instrument: $overrides['instrument'] ?? Mockery::mock(PaymentInstrument::class),
        amount: $overrides['money'] ?? new Money(1000, new Currency('USD')),
        clientUniqueId: $overrides['clientUniqueId'] ?? null,
        threeDS: $overrides['threeDS'] ?? null,
        statementDescription: $overrides['statementDescription'] ?? null,
        description: $overrides['description'] ?? null,
        initiation: $overrides['initiation'] ?? PaymentInitiation::CardholderInitiated,
        customer: cpCustomerFromOverrides($overrides),
    );
}

/**
 * The one customer a command now takes, assembled from the override keys these tests have always
 * used.
 *
 * `billingAddress`, `customerId` and `customerIdentity` were three separate command fields and
 * are one; the keys stay because what each test is *saying* has not changed — "billed here",
 * "for this customer", "who is this person" — and rewriting sixty call sites to say it a new way
 * would bury the change that matters in the change that does not.
 *
 * Naming any one of them now yields a whole customer, which is the design: an address with nobody
 * attached to it is not expressible any more, so a test that gives an address gives a payer. A
 * test that means "no payer at all" names none of the three and gets null.
 *
 * @param  array<string, mixed>  $overrides
 */
function cpCustomerFromOverrides(array $overrides): ?Customer
{
    if (array_key_exists('customer', $overrides)) {
        return $overrides['customer'];
    }

    $named = ['billingAddress', 'customerId', 'customerIdentity'];
    if (! array_filter($named, static fn (string $k): bool => ($overrides[$k] ?? null) !== null)) {
        return null;
    }

    /** @var ?CustomerIdentity $identity */
    $identity = $overrides['customerIdentity'] ?? null;

    return connexPaySuiteCustomer(
        id: $overrides['customerId'] ?? null,
        firstName: $identity->firstName ?? 'Test',
        lastName: $identity->lastName ?? 'User',
        email: $identity->email ?? null,
        phone: $identity->phone ?? null,
        address: $overrides['billingAddress'] ?? null,
    );
}

function cpCaptureCommand(?string $clientUniqueId = null, ?CustomerId $customerId = null): CaptureCommand
{
    return new CaptureCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'auth-guid-abc',
        amount: new Money(5000, new Currency('USD')),
        clientUniqueId: $clientUniqueId,
        customer: $customerId === null ? null : connexPaySuiteCustomer(id: $customerId),
    );
}

function cpCapture(?string $clientUniqueId = null, ?ConnexPayHttpClientInterface $client = null, ?CustomerId $customerId = null): Capture
{
    return new Capture(
        cpSettings(['deviceGuid' => 'device-123']),
        cpCaptureCommand($clientUniqueId, $customerId),
        $client ?? cpHttpClient(),
    );
}

function cpVoid(?string $clientUniqueId = null, ?ConnexPayHttpClientInterface $client = null): VoidTransaction
{
    return new VoidTransaction(
        cpSettings(['deviceGuid' => 'device-789']),
        new CancelCommand(GatewayId::generate(), 'auth-guid-xyz', $clientUniqueId),
        $client ?? cpHttpClient(),
    );
}

/**
 * A successful attestation, in the one shape ConnexPay honours: everything bound and a Cavv
 * present. The Cavv is the argument because it is the field the gateway refuses without.
 */
function cpThreeDS(string $authenticationValue = 'cavv-value', ?ECICode $eci = ECICode::MastercardSuccessful): ThreeDSResult
{
    return new ThreeDSResult(
        ThreeDSStatus::Successful,
        $authenticationValue,
        $eci,
        'ds-txn-123',
        'acs-txn-456',
        ThreeDSVersion::V220,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cpVault(array $overrides = []): VaultCommand
{
    return new VaultCommand(
        gatewayId: $overrides['gatewayId'] ?? GatewayId::generate(),
        instrument: $overrides['instrument'] ?? Mockery::mock(PaymentInstrument::class),
        clientUniqueId: $overrides['clientUniqueId'] ?? null,
        customer: cpCustomerFromOverrides($overrides),
    );
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
function connexPaySuiteCustomer(
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
