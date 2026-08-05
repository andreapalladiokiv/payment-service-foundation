<?php

declare(strict_types=1);

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\PartialCaptureRequest;
use Techork\PaymentService\ConnexPay\PurchaseRequest;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

const HPP_PATH = '/api/v1/HostedPaymentPageRequests';

const HPP_PI_ID = '01991234-0000-7000-8000-aabbccddeeff';

function hostedCpCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'ConnexPay'; }
        public function getCredentials(): array { return []; }
    };
}

function hostedCpBillingAddress(): BillingAddress
{
    return new BillingAddress(
        firstName: 'Ada',
        lastName: 'Lovelace',
        line: '1 Test St',
        city: 'Los Angeles',
        country: new Country('US'),
        postalCode: '90001',
        state: new State('CA'),
        email: new Email('ada@example.test'),
    );
}

function hostedCpInstrument(): HostedPayment
{
    return new HostedPayment(
        successUrl: 'https://merchant.example/paid',
        cancelUrl: 'https://merchant.example/cancelled',
    );
}

/**
 * @param  array<string, mixed>  $override
 */
function hostedCpRequest(array $override = []): PurchaseRequest
{
    $request = new PurchaseRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1050, new Currency('USD')),
        'instrument' => hostedCpInstrument(),
        'gateway' => hostedCpCredential(),
        'deviceGuid' => 'device-1',
        'merchantName' => 'Techork Store',
        'billingAddress' => hostedCpBillingAddress(),
        'clientUniqueId' => HPP_PI_ID,
        ...$override,
    ]);

    return $request;
}

// ──────────────────────────────────────────────
//  payload
// ──────────────────────────────────────────────

it('builds the hosted-page payload instead of a sale', function () {
    $data = hostedCpRequest()->getData();

    expect($data['_hosted'])->toBeTrue();

    $payload = $data['_hostedPayload'];

    expect($payload['MerchantName'])->toBe('Techork Store')
        ->and($payload['ResultRedirectUrl'])->toBe('https://merchant.example/paid')
        ->and($payload['CancelUrl'])->toBe('https://merchant.example/cancelled')
        ->and($payload['TenderTypeOptions'])->toBe(['Credit'])
        // Amount and DeviceGuid are validated on Sale itself — inside
        // ConnexpayTransaction the API ignores them.
        ->and($payload['Sale']['Amount'])->toBe(10.50)
        ->and($payload['Sale']['DeviceGuid'])->toBe('device-1')
        ->and($payload['Sale']['OrderNumber'])->toBe(HPP_PI_ID)
        ->and($payload['Sale']['ConnexpayTransaction'])->toBe(['ExpectedPayments' => 1])
        ->and($payload['Sale']['RiskData']['Name'])->toBe('Ada Lovelace')
        ->and($payload['Sale']['RiskData']['BillingPostalCode'])->toBe('90001');
});

it('sends no sale-shaped keys on the hosted payload', function () {
    $data = hostedCpRequest()->getData();

    expect($data)->toHaveKeys(['_hosted', '_hostedPayload'])
        ->and($data)->not->toHaveKey('TenderType')
        ->and($data)->not->toHaveKey('Card')
        ->and($data)->not->toHaveKey('Amount');
});

it('expires the hosted page four hours out, in UTC', function () {
    $payload = hostedCpRequest()->getData()['_hostedPayload'];

    $expiration = new DateTimeImmutable($payload['Expiration'], new DateTimeZone('UTC'));
    $expected = new DateTimeImmutable('now', new DateTimeZone('UTC'))->add(new DateInterval('PT4H'));

    expect(abs($expiration->getTimestamp() - $expected->getTimestamp()))->toBeLessThan(120);
});

it('forwards the statement description as the buyer-facing description', function () {
    $payload = hostedCpRequest(['statementDescription' => 'ACME ORDER 42'])->getData()['_hostedPayload'];

    expect($payload['Description'])->toBe('ACME ORDER 42');
});

it('omits Description when there is no statement description', function () {
    expect(hostedCpRequest()->getData()['_hostedPayload'])->not->toHaveKey('Description');
});

it('refuses a hosted payment without a merchant name', function () {
    hostedCpRequest(['merchantName' => ''])->getData();
})->throws(RuntimeException::class, 'require a `merchant_name` credential');

it('refuses a hosted payment without a billing address', function () {
    hostedCpRequest(['billingAddress' => null])->getData();
})->throws(RuntimeException::class, 'mandates Sale.RiskData');

// ──────────────────────────────────────────────
//  send
// ──────────────────────────────────────────────

it('returns a redirect challenge built from the temp token', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with(HPP_PATH, Mockery::on(fn (array $body): bool => $body['Sale']['OrderNumber'] === HPP_PI_ID))
        ->andReturn([
            'merchantName' => 'Techork Store',
            'amount' => 10.50,
            'otherUrl' => 'https://sandbox.cxppayments.com/HostedPaymentResult',
            'resultRedirectUrl' => 'https://merchant.example/paid',
            'cancelUrl' => 'https://merchant.example/cancelled',
            'tempToken' => 'tok-abc-123',
            'idHostedPaymentPageRequest' => 50792,
            'expired' => false,
        ]);

    $response = hostedCpRequest(['connexPayClient' => $client])->send();

    $challenge = $response->getChallenge();

    expect($response->isSuccessful())->toBeFalse()
        ->and($challenge)->toBeInstanceOf(RedirectChallenge::class)
        ->and($challenge->url)->toBe('https://sandbox.cxppayments.com/HostedPaymentPage/tok-abc-123')
        ->and($challenge->formFields)->toBe([])
        // The reference is our OrderNumber: ConnexPay has no sale guid to give
        // yet, and the webhook correlates on exactly this value.
        ->and($challenge->transactionId)->toBe(HPP_PI_ID)
        ->and($response->getTransactionReference())->toBe(HPP_PI_ID);
});

it('derives the page host from otherUrl rather than assuming one', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andReturn([
        'otherUrl' => 'https://pay.cxppayments.com/HostedPaymentResult',
        'tempToken' => 'tok-prod',
    ]);

    $challenge = hostedCpRequest(['connexPayClient' => $client])->send()->getChallenge();

    expect($challenge->url)->toBe('https://pay.cxppayments.com/HostedPaymentPage/tok-prod');
});

it('reports a token with no derivable host instead of silently dropping the challenge', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andReturn(['tempToken' => 'tok-orphan']);

    $response = hostedCpRequest(['connexPayClient' => $client])->send();

    expect($response->getChallenge())->toBeNull()
        ->and($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toContain('no otherUrl to derive the page host');
});

it('surfaces a transport failure as a failed response', function () {
    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andThrow(new BadResponseException(
        'Client error',
        new GuzzleRequest('POST', HPP_PATH),
        new GuzzleResponse(422, [], json_encode(['message' => 'Amount cannot be less than 0.5'])),
    ));

    $response = hostedCpRequest(['connexPayClient' => $client])->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getChallenge())->toBeNull()
        ->and($response->getMessage())->toContain('Client error');
});

// ──────────────────────────────────────────────
//  partial capture must not inherit the hosted branch
// ──────────────────────────────────────────────

it('refuses a hosted instrument on a partial capture', function () {
    $request = new PartialCaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(1050, new Currency('USD')),
        'instrument' => hostedCpInstrument(),
        'gateway' => hostedCpCredential(),
        'deviceGuid' => 'device-1',
        'merchantName' => 'Techork Store',
        'billingAddress' => hostedCpBillingAddress(),
        'transactionReference' => 'auth-guid',
    ]);

    $request->getData();
})->throws(UnsupportedInstrument::class, 'on the "partialCapture" operation');
