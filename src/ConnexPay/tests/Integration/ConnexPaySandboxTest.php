<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\ConnexPay\AuthorizeRequest;
use Techork\PaymentService\ConnexPay\CaptureRequest;
use Techork\PaymentService\ConnexPay\ConnexPayClient;
use Techork\PaymentService\ConnexPay\CreateCardRequest;
use Techork\PaymentService\ConnexPay\CreatePaymentMethodRequest;
use Techork\PaymentService\ConnexPay\PartialCaptureRequest;
use Techork\PaymentService\ConnexPay\PurchaseRequest;
use Techork\PaymentService\ConnexPay\RefundRequest;
use Techork\PaymentService\ConnexPay\VoidRequest;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

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

function connexpaySandboxDeviceGuid(): string
{
    return (string) getenv('CONNEXPAY_SANDBOX_DEVICE_GUID');
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
    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money($amountMinor, new Currency('USD')),
        'instrument' => connexpaySandboxCard(),
        'gateway' => connexpaySandboxCredential(),
        'decrypter' => connexpaySandboxDecrypter(),
        'billingAddress' => connexpaySandboxBilling(),
        'deviceGuid' => connexpaySandboxDeviceGuid(),
        'connexPayClient' => connexpaySandboxClient(),
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue($response->getMessage() ?? 'sale failed');

    return [(string) $response->getTransactionReference(), $response->getTransactionMetadata()];
}

/**
 * Sandbox auths are processed by an async job on ConnexPay's side: a
 * capture (or partial-capture void) issued too early fails with
 * "Authorization for the Capture was not processed successfully" / "was not
 * processed". Retry the operation while that's the failure mode.
 */
function connexpaySandboxRetry(callable $sendAttempt): object
{
    $response = $sendAttempt();

    for ($i = 0; $i < 10 && ! $response->isSuccessful() && str_contains((string) $response->getMessage(), 'not processed'); $i++) {
        sleep(6);
        $response = $sendAttempt();
    }

    return $response;
}

function connexpaySandboxAuth(int $amountMinor): string
{
    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money($amountMinor, new Currency('USD')),
        'instrument' => connexpaySandboxCard(),
        'gateway' => connexpaySandboxCredential(),
        'decrypter' => connexpaySandboxDecrypter(),
        'billingAddress' => connexpaySandboxBilling(),
        'deviceGuid' => connexpaySandboxDeviceGuid(),
        'connexPayClient' => connexpaySandboxClient(),
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue($response->getMessage() ?? 'authonly failed');

    return (string) $response->getTransactionReference();
}

it('verifies a card whose billing city carries accents', function () {
    $request = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => connexpaySandboxCard(),
        'decrypter' => connexpaySandboxDecrypter(),
        'billingAddress' => connexpaySandboxBilling(city: 'München'),
        'deviceGuid' => connexpaySandboxDeviceGuid(),
        'connexPayClient' => connexpaySandboxClient(),
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue($response->getMessage() ?? 'verify failed')
        ->and($response->getTransactionReference())->not->toBeEmpty();
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('charges a sale and surfaces the incoming transaction code', function () {
    [$saleGuid, $metadata] = connexpaySandboxSale(503);

    expect($saleGuid)->not->toBeEmpty()
        ->and($metadata)->toHaveKey('incoming_transaction_code')
        ->and($metadata['incoming_transaction_code'])->not->toBeEmpty();
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('refunds an unsettled sale via the void fallback', function () {
    [$saleGuid] = connexpaySandboxSale(507);

    $request = new RefundRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(507, new Currency('USD')),
        'transactionReference' => $saleGuid,
        'deviceGuid' => connexpaySandboxDeviceGuid(),
        'connexPayClient' => connexpaySandboxClient(),
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue($response->getMessage() ?? 'refund failed');
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('captures a held auth and reports the sale guid with its incoming transaction code', function () {
    $authGuid = connexpaySandboxAuth(511);

    $response = connexpaySandboxRetry(function () use ($authGuid) {
        $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
        $request->initialize([
            'transactionReference' => $authGuid,
            'deviceGuid' => connexpaySandboxDeviceGuid(),
            'connexPayClient' => connexpaySandboxClient(),
        ]);

        return $request->send();
    });

    expect($response->isSuccessful())->toBeTrue($response->getMessage() ?? 'capture failed')
        ->and($response->getTransactionReference())->not->toBeEmpty()
        ->and($response->getTransactionReference())->not->toBe($authGuid)
        ->and($response->getTransactionMetadata())->toHaveKey('incoming_transaction_code');
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('partially captures a held auth by voiding and reselling the smaller amount', function () {
    $authGuid = connexpaySandboxAuth(531);

    $response = connexpaySandboxRetry(function () use ($authGuid) {
        $request = new PartialCaptureRequest(new OmnipayClient, new HttpRequest);
        $request->initialize([
            'transactionReference' => $authGuid,
            'money' => new Money(303, new Currency('USD')),
            'instrument' => connexpaySandboxCard(),
            'gateway' => connexpaySandboxCredential(),
            'decrypter' => connexpaySandboxDecrypter(),
            'billingAddress' => connexpaySandboxBilling(),
            'deviceGuid' => connexpaySandboxDeviceGuid(),
            'connexPayClient' => connexpaySandboxClient(),
        ]);

        return $request->send();
    });

    expect($response->isSuccessful())->toBeTrue($response->getMessage() ?? 'partial capture failed')
        ->and($response->getTransactionReference())->not->toBeEmpty()
        ->and($response->getTransactionReference())->not->toBe($authGuid);
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('registers a token as a payment method via verify and returns the customer guid', function () {
    // Tokenize a raw card first — its guid plays the stored-token role.
    $createCard = new CreateCardRequest(new OmnipayClient, new HttpRequest);
    $createCard->initialize([
        'instrument' => connexpaySandboxCard(),
        'decrypter' => connexpaySandboxDecrypter(),
        'billingAddress' => connexpaySandboxBilling(),
        'deviceGuid' => connexpaySandboxDeviceGuid(),
        'connexPayClient' => connexpaySandboxClient(),
    ]);
    $tokenized = $createCard->send();
    expect($tokenized->isSuccessful())->toBeTrue($tokenized->getMessage() ?? 'tokenize failed');

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn($tokenized->getTransactionReference());

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => new Token(TokenId::generate(), connexpaySandboxCard(), ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour'))),
        'gateway' => connexpaySandboxCredential(),
        'decrypter' => connexpaySandboxDecrypter(),
        'referenceResolver' => $resolver,
        'billingAddress' => connexpaySandboxBilling(),
        'deviceGuid' => connexpaySandboxDeviceGuid(),
        'connexPayClient' => connexpaySandboxClient(),
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue($response->getMessage() ?? 'verify failed')
        ->and($response->getTransactionReference())->not->toBeEmpty()
        ->and($response->getCustomerReference())->not->toBeEmpty();
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);

it('voids a held auth', function () {
    $authGuid = connexpaySandboxAuth(513);

    $request = new VoidRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => $authGuid,
        'deviceGuid' => connexpaySandboxDeviceGuid(),
        'connexPayClient' => connexpaySandboxClient(),
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue($response->getMessage() ?? 'void failed');
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

    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1099, new Currency('USD')),
        'instrument' => new HostedPayment(
            successUrl: 'https://foundation-tests.example/paid',
            cancelUrl: 'https://foundation-tests.example/cancelled',
        ),
        'gateway' => connexpaySandboxCredential(),
        'billingAddress' => connexpaySandboxBilling(),
        'merchantName' => 'Foundation Tests',
        'clientUniqueId' => $paymentIntentId,
        'deviceGuid' => connexpaySandboxDeviceGuid(),
        'connexPayClient' => connexpaySandboxClient(),
    ]);

    $response = $request->send();
    $challenge = $response->getChallenge();

    expect($challenge)->toBeInstanceOf(RedirectChallenge::class, $response->getMessage() ?? 'hosted page request failed')
        ->and($challenge->url)->toContain('/HostedPaymentPage/')
        // The reference the sale webhook will have to correlate on, since the
        // response carries no sale guid.
        ->and($challenge->transactionId)->toBe($paymentIntentId)
        ->and($response->getTransactionReference())->toBe($paymentIntentId)
        ->and($response->isSuccessful())->toBeFalse();
})->skip(! connexpaySandboxConfigured(), CONNEXPAY_SANDBOX_SKIP);
