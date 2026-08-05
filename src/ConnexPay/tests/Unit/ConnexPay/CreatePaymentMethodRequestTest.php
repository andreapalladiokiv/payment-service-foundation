<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\CreatePaymentMethodRequest;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function pmCpCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'connexpay'; }
        public function getCredentials(): array { return []; }
    };
}

function pmCpToken(): Token
{
    return new Token(
        TokenId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );
}

it('builds a verify payload from the token reference with the cardholder Customer', function () {
    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('card-guid-abc');

    $decrypter = Mockery::mock(DecryptInterface::class);
    $decrypter->shouldReceive('decrypt')->andReturn('');

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => pmCpToken(),
        'gateway' => pmCpCredential(),
        'decrypter' => $decrypter,
        'referenceResolver' => $ref,
        'deviceGuid' => 'device-9',
        'billingAddress' => new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    ]);

    $data = $request->getData();

    expect($data['DeviceGuid'])->toBe('device-9')
        ->and($data['Card']['Guid'])->toBe('card-guid-abc')
        ->and($data['Card']['Customer']['FirstName'])->toBe('Test')
        ->and($data['Card']['Customer']['City'])->toBe('NYC');
});

it('sends verify and maps the verified card guid and customer guid', function () {
    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('card-guid-abc');

    $decrypter = Mockery::mock(DecryptInterface::class);
    $decrypter->shouldReceive('decrypt')->andReturn('');

    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/verify', Mockery::on(fn (array $d): bool => $d['Card']['Guid'] === 'card-guid-abc'))
        ->andReturn([
            'wasProcessed' => true,
            'status' => 'Transaction - Approved',
            'addressVerificationCode' => 'Y',
            'cvvVerificationCode' => 'M',
            'card' => [
                'guid' => 'verified-guid-1',
                'customer' => ['guid' => 'customer-guid-1'],
            ],
        ]);

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => pmCpToken(),
        'gateway' => pmCpCredential(),
        'decrypter' => $decrypter,
        'referenceResolver' => $ref,
        'deviceGuid' => 'device-9',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('verified-guid-1')
        ->and($response->getCustomerReference())->toBe('customer-guid-1')
        ->and($response->getCvcCheck())->not->toBeNull();
});

it('builds a verify payload from a raw credit card', function () {
    $decrypter = Mockery::mock(DecryptInterface::class);
    $decrypter->shouldReceive('decrypt')->andReturnUsing(fn (string $d): string => $d);

    $card = new CreditCard(
        Number::fromNumber('4012000098765439', new class implements EncryptInterface {
            public function encrypt(string $d): string { return $d; }
        }),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        new Cvc,
    );

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $card,
        'gateway' => pmCpCredential(),
        'decrypter' => $decrypter,
        'deviceGuid' => 'device-9',
    ]);

    $data = $request->getData();

    expect($data['Card']['CardNumber'])->toBe('4012000098765439')
        ->and($data['Card']['ExpirationDate'])->toBe('3012')
        ->and($data['Card']['CardHolderName'])->toBe('Test User');
});

it('throws on cash instrument', function () {
    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => new Cash,
        'gateway' => pmCpCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
    ]);

    $request->getData();
})->throws(RuntimeException::class, 'Cash cannot be stored');

it('throws on payment method instrument', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $pm,
        'gateway' => pmCpCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
    ]);

    $request->getData();
})->throws(RuntimeException::class, 'PaymentMethod cannot be re-stored');
