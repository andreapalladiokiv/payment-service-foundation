<?php

declare(strict_types=1);

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\ConnexPay\PartialCapture;
use Techork\PaymentService\ConnexPay\Purchase;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

const HPP_PATH = '/api/v1/HostedPaymentPageRequests';

const HPP_PI_ID = '01991234-0000-7000-8000-aabbccddeeff';

function hostedCpBillingAddress(): BillingAddress
{
    return new BillingAddress(
        line: '1 Test St',
        city: 'Los Angeles',
        country: new Country('US'),
        postalCode: '90001',
        state: new State('CA'),
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
 * @param  array<string, mixed>  $command
 * @param  array<string, mixed>  $wiring
 */
function hostedCpPurchase(array $command = [], array $wiring = []): Purchase
{
    return new Purchase(
        cpSettings(['merchantName' => $wiring['merchantName'] ?? 'Techork Store']),
        cpPlacement([
            'money' => new Money(1050, new Currency('USD')),
            'instrument' => hostedCpInstrument(),
            'customer' => connexPaySuiteCustomer(address: hostedCpBillingAddress()),
            'clientUniqueId' => HPP_PI_ID,
            ...$command,
        ]),
        cpInfrastructure(),
        $wiring['client'] ?? cpHttpClient(),
    );
}

// ──────────────────────────────────────────────
//  payload
// ──────────────────────────────────────────────

it('builds the hosted-page payload instead of a sale', function () {
    $payload = hostedCpPurchase()->payload();

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
    $payload = hostedCpPurchase()->payload();

    expect($payload)->toHaveKeys(['MerchantName', 'Sale'])
        ->and($payload)->not->toHaveKey('TenderType')
        ->and($payload)->not->toHaveKey('Card')
        ->and($payload)->not->toHaveKey('Amount');
});

it('expires the hosted page four hours out, in UTC', function () {
    $payload = hostedCpPurchase()->payload();

    $expiration = new DateTimeImmutable($payload['Expiration'], new DateTimeZone('UTC'));
    $expected = new DateTimeImmutable('now', new DateTimeZone('UTC'))->add(new DateInterval('PT4H'));

    expect(abs($expiration->getTimestamp() - $expected->getTimestamp()))->toBeLessThan(120);
});

it('forwards the statement description as the buyer-facing description', function () {
    $payload = hostedCpPurchase(['statementDescription' => 'ACME ORDER 42'])->payload();

    expect($payload['Description'])->toBe('ACME ORDER 42');
});

it('omits Description when there is no statement description', function () {
    expect(hostedCpPurchase()->payload())->not->toHaveKey('Description');
});

it('refuses a hosted payment without a merchant name', function () {
    hostedCpPurchase(wiring: ['merchantName' => ''])->payload();
})->throws(RuntimeException::class, 'require a `merchant_name` credential');

/**
 * The refusal is now about the customer, not the address, and that is the same requirement said
 * once instead of twice: ConnexPay mandates `Sale.RiskData` for card tenders, and that block
 * names the payer as well as where they are billed.
 */
it('refuses a hosted payment that names no customer', function () {
    hostedCpPurchase(['customer' => null])->payload();
})->throws(RuntimeException::class, 'mandates Sale.RiskData');

// ──────────────────────────────────────────────
//  charge
// ──────────────────────────────────────────────

it('returns a redirect challenge built from the temp token', function () {
    $client = cpHttpClient();
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

    $result = hostedCpPurchase(wiring: ['client' => $client])->charge();

    expect($result->success)->toBeFalse()
        ->and($result->isRequiresAction())->toBeTrue()
        ->and($result->challenge)->toBeInstanceOf(RedirectChallenge::class)
        ->and($result->challenge->url)->toBe('https://sandbox.cxppayments.com/HostedPaymentPage/tok-abc-123')
        ->and($result->challenge->formFields)->toBe([])
        // The reference is our OrderNumber: ConnexPay has no sale guid to give
        // yet, and the webhook correlates on exactly this value.
        ->and($result->challenge->transactionId)->toBe(HPP_PI_ID)
        ->and($result->reference)->toBe(HPP_PI_ID);
});

it('derives the page host from otherUrl rather than assuming one', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn([
        'otherUrl' => 'https://pay.cxppayments.com/HostedPaymentResult',
        'tempToken' => 'tok-prod',
    ]);

    expect(hostedCpPurchase(wiring: ['client' => $client])->charge()->challenge->url)
        ->toBe('https://pay.cxppayments.com/HostedPaymentPage/tok-prod');
});

it('reports a token with no derivable host instead of silently dropping the challenge', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn(['tempToken' => 'tok-orphan']);

    $result = hostedCpPurchase(wiring: ['client' => $client])->charge();

    expect($result->challenge)->toBeNull()
        ->and($result->success)->toBeFalse()
        ->and($result->message)->toContain('no otherUrl to derive the page host');
});

it('surfaces a transport failure as a failed response', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new BadResponseException(
        'Client error',
        new GuzzleRequest('POST', HPP_PATH),
        new GuzzleResponse(422, [], json_encode(['message' => 'Amount cannot be less than 0.5'])),
    ));

    $result = hostedCpPurchase(wiring: ['client' => $client])->charge();

    expect($result->success)->toBeFalse()
        ->and($result->challenge)->toBeNull()
        ->and($result->message)->toContain('Client error');
});

// ──────────────────────────────────────────────
//  partial capture must not reach the hosted branch
// ──────────────────────────────────────────────

/**
 * Unreachable today — a hosted intent is `Immediate` by invariant, so it is charged rather than
 * authorized and never reaches capture. Stated anyway: composing {@see Purchase} means a partial
 * capture would otherwise open a hosted page and ask the buyer to pay a second time.
 */
it('refuses a hosted instrument on a partial capture', function () {
    new PartialCapture(
        cpSettings(),
        new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'auth-guid',
            amount: new Money(1050, new Currency('USD')),
            instrument: hostedCpInstrument(),
        ),
        cpInfrastructure(),
        cpHttpClient(),
    )->payload();
})->throws(UnsupportedInstrument::class, 'on the "partialCapture" operation');
