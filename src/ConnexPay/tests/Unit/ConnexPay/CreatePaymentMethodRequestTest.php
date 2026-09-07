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
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;
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

/**
 * `Card.Customer` is where ConnexPay's own customer comes from, and it used to come from an
 * address.
 *
 * The block does two jobs: the four person fields are the customer ConnexPay creates or links and
 * hands back as `card.customer.guid`, and the six address fields are the AVS payload. Only the
 * first four were ever the address's business by accident — so the identity takes those and the
 * address keeps the rest. Replacing the block outright with the identity would have registered the
 * right person and silently ended address verification.
 */
it('builds the ConnexPay customer from the identity and the AVS block from the address', function () {
    $ref = Mockery::mock(GatewayInstrumentRepository::class);
    $ref->shouldReceive('find')->andReturn('card-guid-abc');

    $decrypter = Mockery::mock(DecryptInterface::class);
    $decrypter->shouldReceive('decrypt')->andReturn('');

    // Deliberately two different people. The card is billed to Babbage; the customer the merchant
    // recorded is Lovelace. Before the identity arrived here, ConnexPay was told the customer was
    // Babbage, because the address was the only thing that reached it.
    $address = new BillingAddress(
        'Charles',
        'Babbage',
        '1 Main St',
        'NYC',
        new Country('US'),
        '10001',
        email: new Email('charles@example.test'),
        phone: new PhoneNumber('+12125550100'),
    );

    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'instrument' => pmCpToken(),
        'gateway' => pmCpCredential(),
        'decrypter' => $decrypter,
        'referenceResolver' => $ref,
        'deviceGuid' => 'device-9',
        'billingAddress' => $address,
        'customerIdentity' => new CustomerIdentity(
            'Ada',
            'Lovelace',
            new Email('ada@example.test'),
            new PhoneNumber('+12125550199'),
        ),
    ]);

    $customer = $request->getData()['Card']['Customer'];

    expect($customer['FirstName'])->toBe('Ada')
        ->and($customer['LastName'])->toBe('Lovelace')
        ->and($customer['Email'])->toBe('ada@example.test')
        ->and($customer['Phone'])->toBe('+12125550199')
        // The address is still the address, which is what keeps AVS alive.
        ->and($customer['Address1'])->toBe('1 Main St')
        ->and($customer['City'])->toBe('NYC')
        ->and($customer['Zip'])->toBe('10001')
        ->and($customer['Country'])->toBe('US');
});

/**
 * Omnipay applies an option only where a matching setter exists, so the setter is the assertion
 * that matters here — a `customerIdentity` nothing declares is dropped without a word, which is
 * exactly how the Nuvei regression in F5 happened.
 */
it('reads the identity through its own setter rather than dropping the option', function () {
    $request = new CreatePaymentMethodRequest(new OmnipayClient, new HttpRequest);
    $identity = new CustomerIdentity('Ada', 'Lovelace');

    $request->initialize(['customerIdentity' => $identity]);

    expect($request->getCustomerIdentity())->toBe($identity);
});

/**
 * An identity with no address is still worth sending: it is the whole reason the block exists.
 * Before, no address meant no `Card.Customer` at all, so a card registered without billing
 * details created a nameless customer or none.
 */
it('sends the customer block for an identity even with no billing address', function () {
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
        'customerIdentity' => new CustomerIdentity('Ada', 'Lovelace'),
    ]);

    $customer = $request->getData()['Card']['Customer'];

    expect($customer['FirstName'])->toBe('Ada')
        ->and($customer['LastName'])->toBe('Lovelace')
        ->and($customer['Address1'])->toBeNull()
        ->and($customer['City'])->toBeNull();
});

/**
 * And with neither, the block stays off the request entirely — an empty `Customer` would ask
 * ConnexPay to create a customer with nothing in it.
 */
it('omits the customer block when there is neither an identity nor an address', function () {
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
    ]);

    expect($request->getData()['Card'])->not->toHaveKey('Customer');
});

/**
 * The address falls back to being the person when nobody was named — the last resort rather than
 * the only path, which is the state every ConnexPay customer used to be created in.
 */
it('falls back to the address for the person when no identity was named', function () {
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
        'billingAddress' => new BillingAddress('Charles', 'Babbage', '1 Main St', 'NYC', new Country('US'), '10001'),
    ]);

    $customer = $request->getData()['Card']['Customer'];

    expect($customer['FirstName'])->toBe('Charles')
        ->and($customer['LastName'])->toBe('Babbage');
});

/**
 * ConnexPay rejects non-ASCII on this block and a customer's own name is where an accent actually
 * turns up — the city was transliterated all along while the name it travelled with was not.
 */
it('transliterates the identity name, which ConnexPay would otherwise reject', function () {
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
        'customerIdentity' => new CustomerIdentity('Zoë', 'Kraków'),
    ]);

    $customer = $request->getData()['Card']['Customer'];

    expect($customer['FirstName'])->toBe('Zoe')
        ->and($customer['LastName'])->toBe('Krakow');
});
