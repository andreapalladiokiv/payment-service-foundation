<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\ConnexPay\CaptureRequest;
use Techork\PaymentService\ConnexPay\ConnexPayGateway;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\PartialCaptureRequest;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;

function partialCaptureInstrument(): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::generate(),
        new CreditCard(new Number('401200', '5439', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('T'), new Cvc),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );
}

function partialCaptureGateway(): ConnexPayGateway
{
    $gateway = new ConnexPayGateway;
    $gateway->initialize(['username' => 'u', 'password' => 'p', 'deviceGuid' => 'device-1']);

    return $gateway;
}

it('routes an equal-amount capture to the plain CaptureRequest', function () {
    $request = partialCaptureGateway()->capture([
        'transactionReference' => 'auth-guid-1',
        'money' => new Money(5000, new Currency('USD')),
        'authorizedAmount' => new Money(5000, new Currency('USD')),
        'instrument' => partialCaptureInstrument(),
    ]);

    expect($request)->toBeInstanceOf(CaptureRequest::class);
});

it('routes a smaller-amount capture to PartialCaptureRequest', function () {
    $request = partialCaptureGateway()->capture([
        'transactionReference' => 'auth-guid-1',
        'money' => new Money(3000, new Currency('USD')),
        'authorizedAmount' => new Money(5000, new Currency('USD')),
        'instrument' => partialCaptureInstrument(),
        'gateway' => cpCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
    ]);

    expect($request)->toBeInstanceOf(PartialCaptureRequest::class);
});

it('falls back to the plain CaptureRequest when the authorized amount is unknown', function () {
    $request = partialCaptureGateway()->capture([
        'transactionReference' => 'auth-guid-1',
        'money' => new Money(3000, new Currency('USD')),
    ]);

    expect($request)->toBeInstanceOf(CaptureRequest::class);
});

it('rejects a capture above the authorized amount', function () {
    partialCaptureGateway()->capture([
        'transactionReference' => 'auth-guid-1',
        'money' => new Money(6000, new Currency('USD')),
        'authorizedAmount' => new Money(5000, new Currency('USD')),
    ]);
})->throws(InvalidArgumentException::class, 'exceeds the authorized amount');

it('rejects a partial capture without the original instrument', function () {
    partialCaptureGateway()->capture([
        'transactionReference' => 'auth-guid-1',
        'money' => new Money(3000, new Currency('USD')),
        'authorizedAmount' => new Money(5000, new Currency('USD')),
    ]);
})->throws(InvalidArgumentException::class, 'without the original instrument');

it('voids the auth and runs a fresh sale for the partial amount', function () {
    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('pm-guid-1');

    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->ordered()
        ->with('/api/v1/void', Mockery::on(fn (array $d): bool => $d['AuthOnlyGuid'] === 'auth-guid-1'))
        ->andReturn(['wasProcessed' => true, 'guid' => 'void-guid']);
    $client->shouldReceive('post')
        ->once()
        ->ordered()
        ->with('/api/v1/sales', Mockery::on(
            fn (array $d): bool => $d['Amount'] === 30.00 && $d['Card']['Guid'] === 'pm-guid-1',
        ))
        ->andReturn([
            'wasProcessed' => true,
            'guid' => 'new-sale-guid',
            'status' => 'Transaction - Approved',
            'connexPayTransaction' => ['incomingTransCode' => 'ICT-77'],
        ]);

    $request = new PartialCaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'auth-guid-1',
        'money' => new Money(3000, new Currency('USD')),
        'instrument' => partialCaptureInstrument(),
        'gateway' => cpCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => $ref,
        'deviceGuid' => 'device-1',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('new-sale-guid')
        ->and($response->getTransactionMetadata())->toBe(['incoming_transaction_code' => 'ICT-77']);
});

it('does not run the sale when the void fails', function () {
    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('pm-guid-1');

    $client = Mockery::mock(ConnexPayHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/void', Mockery::any())
        ->andThrow(new TransferException('void exploded'));

    $request = new PartialCaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'auth-guid-1',
        'money' => new Money(3000, new Currency('USD')),
        'instrument' => partialCaptureInstrument(),
        'gateway' => cpCredential(),
        'decrypter' => Mockery::mock(DecryptInterface::class),
        'referenceResolver' => $ref,
        'deviceGuid' => 'device-1',
        'connexPayClient' => $client,
    ]);

    $response = $request->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toContain('Void before partial capture failed');
});

it('strips the :capture suffix when forwarding clientUniqueId as OrderNumber', function () {
    $request = new CaptureRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'transactionReference' => 'auth-guid-1',
        'deviceGuid' => 'device-1',
        'clientUniqueId' => 'pi-uuid-7:capture',
    ]);

    expect($request->getData()['OrderNumber'])->toBe('pi-uuid-7');
});
