<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
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

function makeTestPaymentMethod(): PaymentMethod
{
    return new PaymentMethod(PaymentMethodId::generate(), makeTestPmCard());
}

// ──────────────────────────────────────────────
//  Construction & properties
// ──────────────────────────────────────────────

it('has TYPE constant set to payment_method', function () {
    expect(PaymentMethod::type())->toBe('payment_method');
});

it('exposes id and instrument, and nothing about a person', function () {
    $pm = makeTestPaymentMethod();

    expect($pm->instrument)->toBeInstanceOf(CreditCard::class)
        ->and($pm->id)->toBeInstanceOf(PaymentMethodId::class);
});

/**
 * The two fields this type no longer has, pinned as absent.
 *
 * A `BillingAddress` was a property here, and that address carried the payer's name, email and
 * phone — so a payment method WAS a person, one uncorrectable copy per stored card, which is how
 * every provider mapper came to read the payer off whatever card it was charging. Whose card this
 * is now lives on an {@see AttachedPaymentMethod}, and where they are billed lives on the
 * customer inside it.
 */
it('carries neither an address nor a customer', function () {
    $pm = makeTestPaymentMethod();

    expect(property_exists($pm, 'billingAddress'))->toBeFalse()
        ->and(property_exists($pm, 'customer'))->toBeFalse()
        ->and($pm->toPayload())->not->toHaveKey('billing_address')
        ->and($pm->toPayload())->not->toHaveKey('customer');
});

it('is valid when instrument is valid', function () {
    expect(makeTestPaymentMethod()->isValid())->toBeTrue();
});

it('is invalid when instrument is invalid', function () {
    $expired = new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(1, 2020),
        new Holder('Test'),
        new Cvc,
    );

    expect(new PaymentMethod(PaymentMethodId::generate(), $expired)->isValid())->toBeFalse();
});

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
        public function visitAttachedPaymentMethod(AttachedPaymentMethod $attached): string
        { return 'attached'; }
        public function visitHostedPayment(HostedPayment $hosted): string
        { return 'hosted'; }
    };

    // The two land on different branches, which is the whole mechanism behind a gateway taking a
    // payment on one and refusing the other.
    expect(makeTestPaymentMethod()->accept($visitor))->toBe('pm');
});

// ──────────────────────────────────────────────
//  Serialization
// ──────────────────────────────────────────────

it('serializes to payload', function () {
    $pm = makeTestPaymentMethod();

    expect($pm->toPayload())->toBe([
        'id' => $pm->id->toString(),
        'type' => 'payment_method',
        'card' => $pm->instrument->toPayload(),
    ]);
});

it('deserializes from payload', function () {
    $original = makeTestPaymentMethod();

    $restored = PaymentMethod::fromPayload($original->toPayload());

    expect($restored->id->toString())->toBe($original->id->toString())
        ->and($restored->instrument)->toBeInstanceOf(CreditCard::class);
});

/**
 * A row written before the address was removed still carries `billing_address`, and it is a
 * nested array with a `type`-less shape that {@see PaymentMethod::fromPayload()} has to step over
 * on its way to the instrument. Ignored rather than read: an address on a payment method has
 * nowhere left to go, and reading it would put the copy back.
 */
it('reads a row that still carries a billing address, keeping only the instrument', function () {
    $pm = makeTestPaymentMethod();
    $payload = $pm->toPayload();
    $payload['billing_address'] = [
        'first_name' => 'Test',
        'last_name' => 'User',
        'line' => '1 St',
        'city' => 'NYC',
        'country' => 'US',
        'postal_code' => '10001',
    ];

    $restored = PaymentMethod::fromPayload($payload);

    expect($restored->id->toString())->toBe($pm->id->toString())
        ->and($restored->instrument->toPayload())->toBe($pm->instrument->toPayload())
        ->and($restored->toPayload())->not->toHaveKey('billing_address');
});

it('survives toPayload/fromPayload roundtrip', function () {
    $original = makeTestPaymentMethod();

    $restored = PaymentMethod::fromPayload($original->toPayload());

    expect($restored->id->toString())->toBe($original->id->toString())
        ->and($restored->isValid())->toBe($original->isValid())
        ->and($restored->toPayload())->toBe($original->toPayload());
});

it('throws when no instrument payload found', function () {
    PaymentMethod::fromPayload([
        'id' => PaymentMethodId::generate()->toString(),
        'type' => 'payment_method',
    ]);
})->throws(InvalidArgumentException::class, 'No instrument payload found');
