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
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

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
        $overrides['customers'] ?? Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
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

function cpBilling(string $city = 'NYC', ?string $email = 'buyer@test.com'): BillingAddress
{
    return new BillingAddress(
        firstName: 'Test',
        lastName: 'User',
        line: '456 Oak',
        city: $city,
        country: new Country('US'),
        postalCode: '90001',
        email: $email === null ? null : new Email($email),
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
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );
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
        billingAddress: $overrides['billingAddress'] ?? null,
        threeDS: $overrides['threeDS'] ?? null,
        statementDescription: $overrides['statementDescription'] ?? null,
        description: $overrides['description'] ?? null,
        initiation: $overrides['initiation'] ?? PaymentInitiation::CardholderInitiated,
    );
}

function cpCaptureCommand(?string $clientUniqueId = null): CaptureCommand
{
    return new CaptureCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'auth-guid-abc',
        amount: new Money(5000, new Currency('USD')),
        clientUniqueId: $clientUniqueId,
    );
}

function cpCapture(?string $clientUniqueId = null, ?ConnexPayHttpClientInterface $client = null): Capture
{
    return new Capture(
        cpSettings(['deviceGuid' => 'device-123']),
        cpCaptureCommand($clientUniqueId),
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
        billingAddress: $overrides['billingAddress'] ?? null,
        clientUniqueId: $overrides['clientUniqueId'] ?? null,
    );
}
