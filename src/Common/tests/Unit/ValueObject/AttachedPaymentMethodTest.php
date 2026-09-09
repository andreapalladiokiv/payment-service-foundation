<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\PaymentInstrumentFactory;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;

function makeAttachedFixture(?Customer $customer = null): AttachedPaymentMethod
{
    return new AttachedPaymentMethod(
        $customer ?? new Customer(
            CustomerId::fromString('01920000-0000-7000-8000-00000000cafe'),
            new CustomerIdentity('Ada', 'Lovelace'),
            BillingAddress::unknown(),
        ),
        new PaymentMethod(
            PaymentMethodId::fromString('01930000-0000-7000-8000-0000000000aa'),
            new CreditCard(
                new Number('424242', '4242', CardBrand::Visa),
                Expiration::fromMonthAndYear(12, 2030),
                new Holder('Test'),
                new Cvc,
            ),
        ),
    );
}

it('is a payment instrument of its own type', function () {
    expect(AttachedPaymentMethod::type())->toBe('attached_payment_method')
        ->and(makeAttachedFixture())->toBeInstanceOf(PaymentInstrument::class);
});

it('exposes the customer and the payment method it pairs', function () {
    $attached = makeAttachedFixture();

    expect($attached->customer->identity->firstName)->toBe('Ada')
        ->and($attached->paymentMethod)->toBeInstanceOf(PaymentMethod::class)
        ->and($attached->id())->toBe($attached->paymentMethod->id->toString());
});

/**
 * Validity is the card's and only the card's. Every part of a {@see Customer} is required, so
 * there is nothing about the payer that could make an attachment invalid — and treating an
 * expired card as "invalid because of who holds it" would put two questions in one answer.
 */
it('reports the validity of the instrument alone', function () {
    $expired = new AttachedPaymentMethod(
        makeAttachedFixture()->customer,
        new PaymentMethod(
            PaymentMethodId::generate(),
            new CreditCard(
                new Number('424242', '4242', CardBrand::Visa),
                Expiration::fromMonthAndYear(1, 2020),
                new Holder('Test'),
                new Cvc,
            ),
        ),
    );

    expect(makeAttachedFixture()->isValid())->toBeTrue()
        ->and($expired->isValid())->toBeFalse();
});

/**
 * Ownership, compared by the identifier's string.
 *
 * The old pairing held a concrete `CustomerId` and could call `equals()` on it; a
 * `CustomerId` is an interface whose whole contract is `toString()`, so the string IS the
 * identity here. What matters is that a card offered for the wrong customer can be caught at all
 * — a caller handing the right card to the wrong person is the mistake this answers.
 */
it('says which customer it belongs to', function () {
    $attached = makeAttachedFixture();

    expect($attached->belongsTo(CustomerId::fromString('01920000-0000-7000-8000-00000000cafe')))->toBeTrue()
        ->and($attached->belongsTo(CustomerId::fromString('01920000-0000-7000-8000-00000000beef')))->toBeFalse();
});

it('round-trips through its payload, customer included', function () {
    $attached = makeAttachedFixture();

    expect(AttachedPaymentMethod::fromPayload($attached->toPayload()))->toEqual($attached);
});

/**
 * Through the factory, because that is how an event stream rebuilds an instrument it only knows
 * by its `type`. A shape the factory cannot name is a payment nothing can replay.
 */
it('is reachable through the instrument factory', function () {
    $attached = makeAttachedFixture();

    expect(PaymentInstrumentFactory::fromPayload($attached->toPayload()))->toEqual($attached);
});
