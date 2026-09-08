<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;
use Techork\PaymentService\Domain\Customer\Command\ForgetCustomerCommand;
use Techork\PaymentService\Domain\Customer\Command\RegisterCustomerCommand;
use Techork\PaymentService\Domain\Customer\CustomerAggregate;
use Techork\PaymentService\Domain\Customer\CustomerStatus;
use Techork\PaymentService\Domain\Customer\Event\CustomerAddressChanged;
use Techork\PaymentService\Domain\Customer\Event\CustomerForgotten;
use Techork\PaymentService\Domain\Customer\Event\CustomerIdentityChanged;
use Techork\PaymentService\Domain\Customer\Event\CustomerRegistered;
use Techork\PaymentService\Domain\Customer\Event\PaymentMethodDetached;
use Techork\PaymentService\Domain\Customer\Event\PaymentMethodRegistered;
use Techork\PaymentService\Domain\Customer\Exception\CustomerForgottenException;
use Techork\PaymentService\Domain\Customer\Exception\PaymentMethodNotDetachable;
use Techork\PaymentService\Domain\Customer\Exception\PaymentMethodNotRegistrable;
use Techork\PaymentService\Domain\Customer\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Domain\Customer\ValueObject\CustomerId;
use Techork\PaymentService\Tests\Support\CustomerAggregateTestCase;

use function EventSauce\EventSourcing\PestTooling\given;
use function EventSauce\EventSourcing\PestTooling\then;

uses(CustomerAggregateTestCase::class);

// ──────────────────────────────────────────────
//  Helpers
// ──────────────────────────────────────────────

function makeCustomerIdentity(?string $email = 'buyer@example.com'): CustomerIdentity
{
    return new CustomerIdentity(
        firstName: 'Ada',
        lastName: 'Lovelace',
        email: $email === null ? null : new Email($email),
        phone: new PhoneNumber('+12025550142'),
    );
}

function makeCustomerAddress(): BillingAddress
{
    return new BillingAddress('Ada', 'Lovelace', '1 Analytical St', 'London', new Country('GB'), 'W1A 1AA');
}

function makeRegisterCustomerCommand(CustomerId $id, ?CustomerIdentity $identity = null): RegisterCustomerCommand
{
    return new readonly class($id, $identity ?? makeCustomerIdentity()) implements RegisterCustomerCommand
    {
        public function __construct(private CustomerId $id, private CustomerIdentity $identity) {}

        public function customerId(): CustomerId { return $this->id; }

        public function identity(): CustomerIdentity { return $this->identity; }
    };
}

// ──────────────────────────────────────────────
//  Registration
// ──────────────────────────────────────────────

it('records the identity it was registered with', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    $aggregate = CustomerAggregate::register(makeRegisterCustomerCommand($id));
    $this->persistAggregateRoot($aggregate);

    then(new CustomerRegistered(makeCustomerIdentity()));
});

/**
 * Today a missing email decides whether a customer exists at all: Stripe would not create one
 * without it until this week, and Nuvei still cannot, because the email *is* the identity
 * there (`Nuvei/src/CreateCustomerRequest.php:39`). Email is optional on a `BillingAddress`,
 * so that made an optional field load-bearing. It must stop being expressible.
 */
it('registers a customer who gave no email', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    $aggregate = CustomerAggregate::register(makeRegisterCustomerCommand($id, makeCustomerIdentity(null)));
    $this->persistAggregateRoot($aggregate);

    expect($aggregate->identity()->email)->toBeNull();

    then(new CustomerRegistered(makeCustomerIdentity(null)));
});

/**
 * An email is not identity. Two customers may share one, and changing it changes nothing about
 * which customer this is — which is exactly what using it as the key at Nuvei gets wrong: a
 * change there orphans the cards stored against the old value.
 */
it('is the same customer after the email changes', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(new CustomerRegistered(makeCustomerIdentity('before@example.com')));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeIdentity(makeCustomerIdentity('after@example.com'));
    $this->persistAggregateRoot($aggregate);

    expect($aggregate->aggregateRootId()->equals($id))->toBeTrue()
        ->and($aggregate->identity()->email?->__toString())->toBe('after@example.com');

    then(new CustomerIdentityChanged(makeCustomerIdentity('after@example.com')));
});

it('lets two customers share one email', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    $first = CustomerAggregate::register(makeRegisterCustomerCommand($id));
    $second = CustomerAggregate::register(makeRegisterCustomerCommand(CustomerId::generate()));

    expect($first->aggregateRootId()->equals($second->aggregateRootId()))->toBeFalse()
        ->and($first->identity()->email?->__toString())->toBe($second->identity()->email?->__toString());

    $this->persistAggregateRoot($first);
    then(new CustomerRegistered(makeCustomerIdentity()));
});

// ──────────────────────────────────────────────
//  Address
// ──────────────────────────────────────────────

it('holds an address of its own', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(new CustomerRegistered(makeCustomerIdentity()));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeAddress(makeCustomerAddress());
    $this->persistAggregateRoot($aggregate);

    expect($aggregate->address()?->city)->toBe('London');

    then(new CustomerAddressChanged(makeCustomerAddress()));
});

// ──────────────────────────────────────────────
//  Erasure
// ──────────────────────────────────────────────

/**
 * Erasure has to leave a stream that still replays. The identity reads as the same stubs a
 * GDPR-shredded payment reads as, and the id still resolves — that is what lets everything
 * pointing at this customer keep pointing at it. See §0.4 of the plan.
 */
it('replays after being forgotten, with the id intact', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(new CustomerRegistered(makeCustomerIdentity()), new CustomerAddressChanged(makeCustomerAddress()));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->forget(new readonly class implements ForgetCustomerCommand
    {
        public function reason(): string { return 'erasure_request'; }
    });
    $this->persistAggregateRoot($aggregate);

    expect($aggregate->aggregateRootId()->equals($id))->toBeTrue()
        ->and($aggregate->status())->toBe(CustomerStatus::Forgotten)
        ->and($aggregate->identity()->firstName)->toBe('[REDACTED]')
        ->and($aggregate->identity()->email)->toBeNull();

    then(new CustomerForgotten('erasure_request'));
});

// ──────────────────────────────────────────────
//  Payment methods it holds
// ──────────────────────────────────────────────

function makeCustomerPaymentMethodId(string $suffix = '01'): PaymentMethodId
{
    return PaymentMethodId::fromString("0199f0a2-1c3a-7b8d-9e4f-0000000000$suffix");
}

function makeCustomerPaymentMethod(string $suffix = '01'): PaymentMethod
{
    return new PaymentMethod(
        makeCustomerPaymentMethodId($suffix),
        new CreditCard(new Number('424242', '4242', CardBrand::Visa), Expiration::fromMonthAndYear(12, 2030), new Holder('Ada Lovelace'), new Cvc),
        makeCustomerAddress(),
    );
}

function makeAttachedPaymentMethod(CustomerId $customerId, string $suffix = '01'): AttachedPaymentMethod
{
    return new AttachedPaymentMethod($customerId, makeCustomerPaymentMethod($suffix));
}

/**
 * A card offered to the wrong customer is refused, and the comparison is a real one.
 *
 * {@see AttachedPaymentMethod} pairs the card with a `CustomerId`, so this is
 * `CustomerId::equals()` against the aggregate's own id — not a string comparison, and not a
 * nullable field that could mean either "nobody" or "not recorded". There is no "names nobody"
 * case to test: the type does not have one.
 */
it('refuses a payment method that belongs to a different customer', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();
    given(new CustomerRegistered(makeCustomerIdentity()));

    $aggregate = $this->retrieveAggregateRoot($id);

    expect(fn () => $aggregate->registerPaymentMethod(makeAttachedPaymentMethod(CustomerId::generate(), '12')))
        ->toThrow(PaymentMethodNotRegistrable::class);
});

/**
 * Registering the same instrument twice is refused rather than deduplicated. Today the same
 * card registered twice is two `gateway_references` rows and nothing says they are one card,
 * which is why identity had to be recovered by asking the provider who owned it.
 */
it('refuses to register the same payment method twice', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(new CustomerRegistered(makeCustomerIdentity()), new PaymentMethodRegistered(makeAttachedPaymentMethod($id)));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->registerPaymentMethod(makeAttachedPaymentMethod($id));
})->throws(PaymentMethodNotRegistrable::class, 'already');

it('holds the payment methods registered to it', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(new CustomerRegistered(makeCustomerIdentity()));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->registerPaymentMethod(makeAttachedPaymentMethod($id, '01'));
    $aggregate->registerPaymentMethod(makeAttachedPaymentMethod($id, '02'));
    $this->persistAggregateRoot($aggregate);

    expect(array_keys($aggregate->paymentMethods()))->toBe([
        makeCustomerPaymentMethodId('01')->toString(),
        makeCustomerPaymentMethodId('02')->toString(),
    ])->and($aggregate->holds(makeCustomerPaymentMethodId('01')))->toBeTrue()
        // The card itself, which is what owning it means — and what lets its address be the
        // customer's rather than a copy per card.
        ->and($aggregate->paymentMethods()[makeCustomerPaymentMethodId('01')->toString()]->paymentMethod->billingAddress->city)->toBe('London')
        // And each entry says whose it is, which is the whole reason it is a pairing.
        ->and($aggregate->paymentMethods()[makeCustomerPaymentMethodId('01')->toString()]->customerId->equals($id))->toBeTrue();

    then(
        new PaymentMethodRegistered(makeAttachedPaymentMethod($id, '01')),
        new PaymentMethodRegistered(makeAttachedPaymentMethod($id, '02')),
    );
});

it('refuses to detach a payment method it does not hold', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(new CustomerRegistered(makeCustomerIdentity()));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->detachPaymentMethod(makeCustomerPaymentMethodId());
})->throws(PaymentMethodNotDetachable::class);

/**
 * A detached instrument stays in the collection with the moment it went, because that memory
 * is the whole of the next invariant. Presence alone does not mean held.
 */
it('stops holding a payment method once it is detached', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(new CustomerRegistered(makeCustomerIdentity()), new PaymentMethodRegistered(makeAttachedPaymentMethod($id)));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->detachPaymentMethod(makeCustomerPaymentMethodId());
    $this->persistAggregateRoot($aggregate);

    expect($aggregate->holds(makeCustomerPaymentMethodId()))->toBeFalse()
        ->and($aggregate->paymentMethods())->toBe([]);

    then(new PaymentMethodDetached(makeCustomerPaymentMethodId()));
});

/**
 * Erasure is about identity, not about what happened. A payment that was made was made, and
 * the instrument that made it is not personal data on its own — the card's own PAN is shredded
 * through the same store either way.
 */
it('keeps holding its payment methods after being forgotten', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(
        new CustomerRegistered(makeCustomerIdentity()),
        new PaymentMethodRegistered(makeAttachedPaymentMethod($id)),
        new CustomerForgotten('erasure_request'),
    );

    $aggregate = $this->retrieveAggregateRoot($id);

    expect($aggregate->holds(makeCustomerPaymentMethodId()))->toBeTrue()
        ->and($aggregate->identity()->firstName)->toBe('[REDACTED]');

    then();
});

/**
 * Forgetting is not a reset. Registering the same id again would put an identity back on a
 * customer whose identity was erased on request, and the aggregate has no way to know whether
 * the new one belongs to the same person — that judgement is the host's and this is not the
 * place to make it silently.
 */
it('refuses to register a customer it has been asked to forget', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(new CustomerRegistered(makeCustomerIdentity()), new CustomerForgotten('erasure_request'));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->changeIdentity(makeCustomerIdentity('back@example.com'));
})->throws(CustomerForgottenException::class);

it('refuses to take a new payment method for a forgotten customer', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(new CustomerRegistered(makeCustomerIdentity()), new CustomerForgotten('erasure_request'));

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->registerPaymentMethod(makeAttachedPaymentMethod($id));
})->throws(CustomerForgottenException::class);

/**
 * Detaching still works, because it takes something away rather than adding it. A card that has
 * to be released at the provider must be releasable here whatever the customer's state.
 */
it('still lets a forgotten customer release a payment method', function () {
    /** @var CustomerId $id */
    $id = $this->aggregateRootId();

    given(
        new CustomerRegistered(makeCustomerIdentity()),
        new PaymentMethodRegistered(makeAttachedPaymentMethod($id)),
        new CustomerForgotten('erasure_request'),
    );

    $aggregate = $this->retrieveAggregateRoot($id);
    $aggregate->detachPaymentMethod(makeCustomerPaymentMethodId());
    $this->persistAggregateRoot($aggregate);

    then(new PaymentMethodDetached(makeCustomerPaymentMethodId()));
});
