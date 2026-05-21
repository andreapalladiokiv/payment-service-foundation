<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\ConnexPay\ReturnRetryRequest;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;

it('builds retry-return data with ReturnRetryCard for a raw credit card', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Alt Holder'),
        Cvc::fromCvc('999', cpEncrypter()),
    );

    $request = new ReturnRetryRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => 'sale-guid-xyz',
        'instrument' => $card,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'deviceGuid' => 'device-456',
    ]);

    $data = $request->getData();

    expect($data['DeviceGuid'])->toBe('device-456')
        ->and($data['SaleGuid'])->toBe('sale-guid-xyz')
        ->and($data['Amount'])->toBe(25.00)
        ->and($data['ReturnRetryCard']['CardNumber'])->toBe('4012000098765439')
        ->and($data['ReturnRetryCard']['ExpirationDate'])->toBe('3012')
        ->and($data['ReturnRetryCard']['Cvv2'])->toBe('999')
        ->and($data['ReturnRetryCard']['CardHolderName'])->toBe('Alt Holder')
        ->and($data)->not->toHaveKey('OrderNumber');
});

it('resolves ReturnRetryCard.Guid from the reference resolver when given a stored Token', function () {
    $future = (new DateTimeImmutable('+1 hour'))->format(DateTimeInterface::ATOM);
    $token = new Token(
        TokenId::fromString('01961f5a-0000-7000-8000-000000000050'),
        new CreditCard(
            new Number('424242', '4242', \Techork\PaymentService\Common\ValueObject\CardBrand::Visa),
            Expiration::fromMonthAndYear(12, 2030),
            new Holder('Test'),
            new Cvc,
        ),
        ExpiresAt::fromString($future),
    );

    $resolver = Mockery::mock(GatewayInstrumentRepository::class);
    $resolver->shouldReceive('find')->andReturn('cnx-tok-ref-7');

    $request = new ReturnRetryRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => 'sale-guid-xyz',
        'instrument' => $token,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'referenceResolver' => $resolver,
        'deviceGuid' => 'device-456',
    ]);

    expect($request->getData()['ReturnRetryCard'])->toBe(['Guid' => 'cnx-tok-ref-7']);
});

it('includes OrderNumber when clientUniqueId is set', function () {
    $card = new CreditCard(
        Number::fromNumber('4012000098765439', cpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Alt Holder'),
        new Cvc,
    );

    $request = new ReturnRetryRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(2500, new Currency('USD')),
        'transactionReference' => 'sale-guid-xyz',
        'instrument' => $card,
        'gateway' => cpCredential(),
        'decrypter' => cpDecrypter(),
        'deviceGuid' => 'device-456',
        'clientUniqueId' => 'refund-retry-9',
    ]);

    expect($request->getData()['OrderNumber'])->toBe('refund-retry-9');
});
