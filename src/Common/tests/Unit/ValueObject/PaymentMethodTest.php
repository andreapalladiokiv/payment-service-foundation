<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\State;
use Techork\PaymentService\Common\ValueObject\Token;

function makeTestPmCard(): CreditCard
{
    return new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );
}

function makeTestBillingAddress(): BillingAddress
{
    return new BillingAddress(
        firstName: 'Test',
        lastName: 'User',
        line: '123 Main St',
        city: 'New York',
        country: new Country('US'),
        postalCode: '10001',
        state: new State('NY'),
        email: new Email('test@example.com'),
    );
}

function makeTestPaymentMethod(): PaymentMethod
{
    return new PaymentMethod(
        PaymentMethodId::generate(),
        makeTestPmCard(),
        makeTestBillingAddress(),
    );
}

// ──────────────────────────────────────────────
//  Construction & properties
// ──────────────────────────────────────────────

it('has TYPE constant set to payment_method', function () {
    expect(PaymentMethod::type())->toBe('payment_method');
});

it('exposes id, instrument and billingAddress', function () {
    $id = PaymentMethodId::generate();
    $card = makeTestPmCard();
    $address = makeTestBillingAddress();

    $pm = new PaymentMethod($id, $card, $address);

    expect($pm->id)->toBe($id)
        ->and($pm->instrument)->toBe($card)
        ->and($pm->billingAddress)->toBe($address);
});

// ──────────────────────────────────────────────
//  isValid
// ──────────────────────────────────────────────

it('is valid when instrument is valid', function () {
    expect(makeTestPaymentMethod()->isValid())->toBeTrue();
});

it('is invalid when instrument is invalid', function () {
    $expiredCard = new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(1, 2020),
        new Holder('Test'),
        new Cvc,
    );

    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        $expiredCard,
        makeTestBillingAddress(),
    );

    expect($pm->isValid())->toBeFalse();
});

// ──────────────────────────────────────────────
//  Visitor
// ──────────────────────────────────────────────

it('accepts visitor', function () {
    $visitor = new class implements PaymentInstrumentVisitor
    {
        public function visitCreditCard(CreditCard $card): string
        { return 'card'; }
        public function visitCash(Cash $cash): string
        { return 'cash'; }
        public function visitToken(Token $token): string
        { return 'token'; }
        public function visitPaymentMethod(PaymentMethod $paymentMethod): string
        { return 'pm'; }
        public function visitHostedPayment(HostedPayment $hosted): string
        { return 'hosted'; }
    };

    expect(makeTestPaymentMethod()->accept($visitor))->toBe('pm');
});

// ──────────────────────────────────────────────
//  Serialization
// ──────────────────────────────────────────────

it('serializes to payload', function () {
    $pm = makeTestPaymentMethod();
    $payload = $pm->toPayload();

    expect($payload['type'])->toBe('payment_method')
        ->and($payload['id'])->toBe($pm->id->toString())
        ->and($payload['card'])->toBe($pm->instrument->toPayload())
        ->and($payload['billing_address'])->toBeArray()
        ->and($payload['billing_address']['first_name'])->toBe('Test')
        ->and($payload['billing_address']['last_name'])->toBe('User')
        ->and($payload['billing_address']['line'])->toBe('123 Main St')
        ->and($payload['billing_address']['city'])->toBe('New York')
        ->and($payload['billing_address']['country'])->toBe('US')
        ->and($payload['billing_address']['postal_code'])->toBe('10001')
        ->and($payload['billing_address']['state'])->toBe('NY')
        ->and($payload['billing_address']['email'])->toBe('test@example.com');
});

it('serializes billing address with null optional fields', function () {
    $pm = new PaymentMethod(
        PaymentMethodId::generate(),
        makeTestPmCard(),
        new BillingAddress(firstName: 'Test', lastName: 'User', line: '1 St', city: 'NYC', country: new Country('US'), postalCode: '10001'),
    );

    $payload = $pm->toPayload();

    expect($payload['billing_address']['state'])->toBeNull()
        ->and($payload['billing_address']['email'])->toBeNull();
});

it('deserializes from payload', function () {
    $original = makeTestPaymentMethod();
    $payload = $original->toPayload();

    $restored = PaymentMethod::fromPayload($payload);

    expect($restored->id->toString())->toBe($original->id->toString())
        ->and($restored->instrument)->toBeInstanceOf(CreditCard::class)
        ->and($restored->billingAddress->line)->toBe('123 Main St')
        ->and((string) $restored->billingAddress->country)->toBe('US')
        ->and((string) $restored->billingAddress->state)->toBe('NY')
        ->and((string) $restored->billingAddress->email)->toBe('test@example.com');
});

it('deserializes from payload with missing optional billing fields', function () {
    $payload = [
        'type' => 'payment_method',
        'id' => PaymentMethodId::generate()->toString(),
        'card' => makeTestPmCard()->toPayload(),
        'billing_address' => [
            'first_name' => 'Test',
            'last_name' => 'User',
            'line' => '1 St',
            'city' => 'NYC',
            'country' => 'US',
            'postal_code' => '10001',
        ],
    ];

    $pm = PaymentMethod::fromPayload($payload);

    expect($pm->billingAddress->state)->toBeNull()
        ->and($pm->billingAddress->email)->toBeNull()
        ->and($pm->billingAddress->lineExtra)->toBe('');
});

it('survives toPayload/fromPayload roundtrip', function () {
    $original = makeTestPaymentMethod();

    $restored = PaymentMethod::fromPayload($original->toPayload());

    expect($restored->id->toString())->toBe($original->id->toString())
        ->and($restored->isValid())->toBe($original->isValid())
        ->and($restored->billingAddress->line)->toBe($original->billingAddress->line);
});

it('throws when no instrument payload found', function () {
    PaymentMethod::fromPayload([
        'type' => 'payment_method',
        'id' => PaymentMethodId::generate()->toString(),
        'billing_address' => [
            'first_name' => 'Test',
            'last_name' => 'User',
            'line' => '1 St',
            'city' => 'NYC',
            'country' => 'US',
            'postal_code' => '10001',
        ],
    ]);
})->throws(InvalidArgumentException::class, 'No instrument payload found');
