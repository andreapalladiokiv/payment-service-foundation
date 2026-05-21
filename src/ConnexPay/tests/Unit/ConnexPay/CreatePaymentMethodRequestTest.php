<?php

declare(strict_types=1);

use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
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

it('builds data from token reference as pass-through', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('card-guid-abc');

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $token,
        'gateway' => pmCpCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => $ref,
    ]);

    $data = $request->getData();

    expect($data['tokenReference'])->toBe('card-guid-abc');
});

it('sendData returns the token reference as guid', function () {
    $token = new Token(
        TokenId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('card-guid-abc');

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $token,
        'gateway' => pmCpCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => $ref,
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('card-guid-abc');
});

it('throws on credit card instrument', function () {
    $card = new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc);

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => $card,
        'gateway' => pmCpCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
    ]);

    $request->getData();
})->throws(RuntimeException::class, 'Credit card must be tokenized');

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
