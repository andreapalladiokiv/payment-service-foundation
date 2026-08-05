<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ExpiresAt;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Common\ValueObject\TokenId;

function makeTestCard(): CreditCard
{
    return new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );
}

function makeTestToken(?DateTimeImmutable $expiresAt = null): Token
{
    return new Token(
        TokenId::generate(),
        makeTestCard(),
        ExpiresAt::fromDateTime($expiresAt ?? new DateTimeImmutable('+1 hour')),
    );
}

// ──────────────────────────────────────────────
//  Construction & properties
// ──────────────────────────────────────────────

it('has TYPE constant set to token', function () {
    expect(Token::type())->toBe('token');
});

it('exposes id, instrument and expiresAt', function () {
    $id = TokenId::generate();
    $card = makeTestCard();
    $expiresAt = ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour'));

    $token = new Token($id, $card, $expiresAt);

    expect($token->id)->toBe($id)
        ->and($token->instrument)->toBe($card)
        ->and($token->expiresAt)->toBe($expiresAt);
});

// ──────────────────────────────────────────────
//  isValid
// ──────────────────────────────────────────────

it('is valid when not expired and instrument is valid', function () {
    expect(makeTestToken()->isValid())->toBeTrue();
});

it('is invalid when expired', function () {
    $token = makeTestToken(new DateTimeImmutable('-1 hour'));

    expect($token->isValid())->toBeFalse();
});

it('is invalid when instrument is invalid', function () {
    $expiredCard = new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(1, 2020),
        new Holder('Test'),
        new Cvc,
    );

    $token = new Token(
        TokenId::generate(),
        $expiredCard,
        ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour')),
    );

    expect($token->isValid())->toBeFalse();
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

    expect(makeTestToken()->accept($visitor))->toBe('token');
});

// ──────────────────────────────────────────────
//  Serialization
// ──────────────────────────────────────────────

it('serializes to payload', function () {
    $token = makeTestToken();
    $payload = $token->toPayload();

    expect($payload['type'])->toBe('token')
        ->and($payload['id'])->toBe($token->id->toString())
        ->and($payload['expires_at'])->toBe($token->expiresAt->toPayload())
        ->and($payload['card'])->toBe($token->instrument->toPayload());
});

it('deserializes from payload', function () {
    $original = makeTestToken();
    $payload = $original->toPayload();

    $restored = Token::fromPayload($payload);

    expect($restored->id->toString())->toBe($original->id->toString())
        ->and($restored->expiresAt->toString())->toBe($original->expiresAt->toString())
        ->and($restored->instrument)->toBeInstanceOf(CreditCard::class);
});

it('survives toPayload/fromPayload roundtrip', function () {
    $original = makeTestToken();

    $restored = Token::fromPayload($original->toPayload());

    expect($restored->id->toString())->toBe($original->id->toString())
        ->and($restored->isValid())->toBe($original->isValid());
});

it('throws when no instrument payload found', function () {
    Token::fromPayload([
        'type' => 'token',
        'id' => TokenId::generate()->toString(),
        'expires_at' => ExpiresAt::fromDateTime(new DateTimeImmutable('+1 hour'))->toPayload(),
    ]);
})->throws(InvalidArgumentException::class, 'No instrument payload found');
