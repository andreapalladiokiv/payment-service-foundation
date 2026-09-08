<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\ConnexPay\Authorize;
use Techork\PaymentService\ConnexPay\Capture;
use Techork\PaymentService\ConnexPay\ConnexPayClient;
use Techork\PaymentService\ConnexPay\ConnexPaySettings;
use Techork\PaymentService\ConnexPay\CreateCard;
use Techork\PaymentService\ConnexPay\CreatePaymentMethod;
use Techork\PaymentService\ConnexPay\PartialCapture;
use Techork\PaymentService\ConnexPay\Purchase;
use Techork\PaymentService\ConnexPay\Refund;
use Techork\PaymentService\ConnexPay\VoidTransaction;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * Live integration tests against the ConnexPay SANDBOX
 * (sandboxsalesapi.connexpay.com). Skipped unless credentials are provided:
 *
 *   CONNEXPAY_SANDBOX_USERNAME=... \
 *   CONNEXPAY_SANDBOX_PASSWORD=... \
 *   CONNEXPAY_SANDBOX_DEVICE_GUID=... \
 *   vendor/bin/pest src/ConnexPay/tests/Integration/ConnexPaySandboxTest.php
 *
 * The scenarios mirror the production flows that broke during the rollout:
 * verify with non-ASCII city, sale + incoming transaction code, auth →
 * capture (sale guid + ICT from the nested envelope), refund of an
 * unsettled sale (→ void fallback), and void of a held auth.
 */
const CONNEXPAY_SANDBOX_SKIP = 'Set CONNEXPAY_SANDBOX_USERNAME / _PASSWORD / _DEVICE_GUID to run ConnexPay sandbox integration tests.';

function connexpaySandboxConfigured(): bool
{
    return (getenv('CONNEXPAY_SANDBOX_USERNAME') ?: '') !== ''
        && (getenv('CONNEXPAY_SANDBOX_PASSWORD') ?: '') !== ''
        && (getenv('CONNEXPAY_SANDBOX_DEVICE_GUID') ?: '') !== '';
}

function connexpaySandboxClient(): ConnexPayClient
{
    static $client = null;

    return $client ??= new ConnexPayClient(
        username: (string) getenv('CONNEXPAY_SANDBOX_USERNAME'),
        password: (string) getenv('CONNEXPAY_SANDBOX_PASSWORD'),
        environment: 'sandbox',
    );
}

function connexpaySandboxSettings(string $merchantName = ''): ConnexPaySettings
{
    return new ConnexPaySettings(
        deviceGuid: (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID'),
        merchantName: $merchantName,
        environment: 'sandbox',
    );
}

function connexpaySandboxCredential(): GatewayCredential
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

function connexpaySandboxEncrypter(): EncryptInterface
{
    return new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };
}

function connexpaySandboxDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };
}

/**
 * The wiring an operation needs beyond its settings and its command. `$reference` stands in for
 * the instrument repository: the sandbox has no local store, so a stored-instrument scenario
 * says outright which guid it means.
 */
function connexpaySandboxInfrastructure(?string $reference = null): GatewayInfrastructure
{
    $instruments = Mockery::mock(GatewayInstrumentRepository::class);
    $instruments->shouldReceive('find')->andReturn($reference);

    return new GatewayInfrastructure(
        connexpaySandboxCredential(),
        connexpaySandboxDecrypter(),
        $instruments,
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
    );
}

function connexpaySandboxCard(): CreditCard
{
    return new CreditCard(
        Number::fromNumber('4111111111111111', connexpaySandboxEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Foundation Test'),
        Cvc::fromCvc('999', connexpaySandboxEncrypter()),
    );
}

function connexpaySandboxBilling(string $city = 'New York'): BillingAddress
{
    return new BillingAddress(
        firstName: 'Foundation',
        lastName: 'Test',
        line: '1 Test St',
        city: $city,
        country: new Country('US'),
        postalCode: '10001',
        email: new Email('foundation-tests@example.com'),
    );
}

/**
 * @return array{0: string, 1: array<string, mixed>} sale guid + metadata
 */
function connexpaySandboxSale(int $amountMinor): array
{
    $result = new Purchase(
        connexpaySandboxSettings(),
        new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: connexpaySandboxCard(),
            amount: new Money($amountMinor, new Currency('USD')),
            billingAddress: connexpaySandboxBilling(),
        ),
        connexpaySandboxInfrastructure(),
        connexpaySandboxClient(),
    )->charge();

    expect($result->success)->toBeTrue($result->message ?? 'sale failed');

    return [(string) $result->reference, $result->metadata];
}

/**
 * Sandbox auths are processed by an async job on ConnexPay's side: a
 * capture (or partial-capture void) issued too early fails with
 * "Authorization for the Capture was not processed successfully" / "was not
 * processed". Retry the operation while that's the failure mode.
 */
function connexpaySandboxRetry(callable $sendAttempt): object
{
    $result = $sendAttempt();

    for ($i = 0; $i < 10 && ! $result->success && str_contains((string) $result->message, 'not processed'); $i++) {
        sleep(6);
        $result = $sendAttempt();
    }

    return $result;
}

function connexpaySandboxAuth(int $amountMinor): string
{
    $result = new Authorize(
        connexpaySandboxSettings(),
        new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: connexpaySandboxCard(),
            amount: new Money($amountMinor, new Currency('USD')),
            billingAddress: connexpaySandboxBilling(),
        ),
        connexpaySandboxInfrastructure(),
        connexpaySandboxClient(),
    )->authorize();

    expect($result->success)->toBeTrue($result->message ?? 'authonly failed');

    return (string) $result->reference;
}

it('verifies a card whose billing city carries accents', function () {
    $result = new CreateCard(
        connexpaySandboxSettings(),
        new VaultCommand(
            gatewayId: GatewayId::generate(),
            instrument: connexpaySandboxCard(),
            billingAddress: connexpaySandboxBilling(city: 'München'),
        ),
        connexpaySandboxInfrastructure(),
        connexpaySandboxClient(),
    )->tokenize();

    expect($result->success)->toBeTrue($result->message ?? 'verify failed')
        ->and($result->reference)->not->toBeEmpty();
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('charges a sale and surfaces the incoming transaction code', function () {
    [$saleGuid, $metadata] = connexpaySandboxSale(503);

    expect($saleGuid)->not->toBeEmpty()
        ->and($metadata)->toHaveKey('incoming_transaction_code')
        ->and($metadata['incoming_transaction_code'])->not->toBeEmpty();
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('refunds an unsettled sale via the void fallback', function () {
    [$saleGuid] = connexpaySandboxSale(507);

    $result = new Refund(
        connexpaySandboxSettings(),
        new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: $saleGuid,
            amount: new Money(507, new Currency('USD')),
        ),
        connexpaySandboxClient(),
    )->refund();

    expect($result->success)->toBeTrue($result->message ?? 'refund failed');
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('captures a held auth and reports the sale guid with its incoming transaction code', function () {
    $authGuid = connexpaySandboxAuth(511);

    $result = connexpaySandboxRetry(fn () => new Capture(
        connexpaySandboxSettings(),
        new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: $authGuid,
            amount: new Money(511, new Currency('USD')),
        ),
        connexpaySandboxClient(),
    )->capture());

    expect($result->success)->toBeTrue($result->message ?? 'capture failed')
        ->and($result->reference)->not->toBeEmpty()
        ->and($result->reference)->not->toBe($authGuid)
        ->and($result->metadata)->toHaveKey('incoming_transaction_code');
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('partially captures a held auth by voiding and reselling the smaller amount', function () {
    $authGuid = connexpaySandboxAuth(531);

    $result = connexpaySandboxRetry(fn () => new PartialCapture(
        connexpaySandboxSettings(),
        new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: $authGuid,
            amount: new Money(303, new Currency('USD')),
            authorizedAmount: new Money(531, new Currency('USD')),
            instrument: connexpaySandboxCard(),
        ),
        connexpaySandboxInfrastructure(),
        connexpaySandboxClient(),
    )->capture());

    expect($result->success)->toBeTrue($result->message ?? 'partial capture failed')
        ->and($result->reference)->not->toBeEmpty()
        ->and($result->reference)->not->toBe($authGuid);
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('registers a token as a payment method via verify and returns the customer guid', function () {
    // Tokenize a raw card first — its guid plays the stored-token role.
    $tokenized = new CreateCard(
        connexpaySandboxSettings(),
        new VaultCommand(
            gatewayId: GatewayId::generate(),
            instrument: connexpaySandboxCard(),
            billingAddress: connexpaySandboxBilling(),
        ),
        connexpaySandboxInfrastructure(),
        connexpaySandboxClient(),
    )->tokenize();

    expect($tokenized->success)->toBeTrue($tokenized->message ?? 'tokenize failed');

    $result = new CreatePaymentMethod(
        connexpaySandboxSettings(),
        new VaultCommand(
            gatewayId: GatewayId::generate(),
            instrument: new Token(TokenId::generate(), connexpaySandboxCard(), ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour'))),
            billingAddress: connexpaySandboxBilling(),
        ),
        connexpaySandboxInfrastructure($tokenized->reference),
        connexpaySandboxClient(),
    )->register();

    expect($result->success)->toBeTrue($result->message ?? 'verify failed')
        ->and($result->reference)->not->toBeEmpty()
        ->and($result->customerReference)->not->toBeEmpty();
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('voids a held auth', function () {
    $authGuid = connexpaySandboxAuth(513);

    $result = new VoidTransaction(
        connexpaySandboxSettings(),
        new CancelCommand(GatewayId::generate(), $authGuid),
        connexpaySandboxClient(),
    )->cancel();

    expect($result->success)->toBeTrue($result->message ?? 'void failed');
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

/**
 * Pins the hosted-payment-page payload against the live API. Worth having as an
 * integration test rather than only a unit one: the field placement this
 * exercises is not documented anywhere — `Amount` and `DeviceGuid` are read off
 * `Sale` and ignored inside `ConnexpayTransaction`, `RiskData` is mandatory for
 * card tenders, and `ConnexpayTransaction` is required but unread. All of it was
 * established by probing, so only a live call notices if ConnexPay changes it.
 *
 * Stops at the token: completing the payment needs a human on ConnexPay's page,
 * so whether `OrderNumber` reaches the sale webhook stays unverified here.
 */
it('creates a hosted payment page and returns a redirect challenge', function () {
    $paymentIntentId = '01991234-0000-7000-8000-'.substr(bin2hex(random_bytes(6)), 0, 12);

    $result = new Purchase(
        connexpaySandboxSettings(merchantName: 'Foundation Tests'),
        new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: new HostedPayment(
                successUrl: 'https://foundation-tests.example/paid',
                cancelUrl: 'https://foundation-tests.example/cancelled',
            ),
            amount: new Money(1099, new Currency('USD')),
            clientUniqueId: $paymentIntentId,
            billingAddress: connexpaySandboxBilling(),
        ),
        connexpaySandboxInfrastructure(),
        connexpaySandboxClient(),
    )->charge();

    expect($result->challenge)->toBeInstanceOf(RedirectChallenge::class, $result->message ?? 'hosted page request failed')
        ->and($result->challenge->url)->toContain('/HostedPaymentPage/')
        // The reference the sale webhook will have to correlate on, since the
        // response carries no sale guid.
        ->and($result->challenge->transactionId)->toBe($paymentIntentId)
        ->and($result->reference)->toBe($paymentIntentId)
        ->and($result->success)->toBeFalse();
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);
