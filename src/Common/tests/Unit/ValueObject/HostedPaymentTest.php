<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;

it('has TYPE constant set to hosted', function () {
    expect(HostedPayment::type())->toBe('hosted');
});

it('is valid when both URLs are well-formed', function () {
    $hosted = new HostedPayment(
        successUrl: 'https://merchant.example/return?outcome=success',
        cancelUrl: 'https://merchant.example/return?outcome=cancel',
    );

    expect($hosted->isValid())->toBeTrue();
});

it('is invalid when successUrl is not a URL', function () {
    $hosted = new HostedPayment(
        successUrl: 'not-a-url',
        cancelUrl: 'https://merchant.example/cancel',
    );

    expect($hosted->isValid())->toBeFalse();
});

it('is invalid when cancelUrl is not a URL', function () {
    $hosted = new HostedPayment(
        successUrl: 'https://merchant.example/success',
        cancelUrl: '',
    );

    expect($hosted->isValid())->toBeFalse();
});

it('serializes to payload with type discriminator and URLs', function () {
    $hosted = new HostedPayment(
        successUrl: 'https://merchant.example/ok',
        cancelUrl: 'https://merchant.example/cancel',
    );

    expect($hosted->toPayload())->toBe([
        'type' => 'hosted',
        'success_url' => 'https://merchant.example/ok',
        'cancel_url' => 'https://merchant.example/cancel',
    ]);
});

it('deserializes from payload', function () {
    $hosted = HostedPayment::fromPayload([
        'type' => 'hosted',
        'success_url' => 'https://x.test/s',
        'cancel_url' => 'https://x.test/c',
    ]);

    expect($hosted)->toBeInstanceOf(HostedPayment::class)
        ->and($hosted->successUrl)->toBe('https://x.test/s')
        ->and($hosted->cancelUrl)->toBe('https://x.test/c');
});

it('survives toPayload/fromPayload roundtrip', function () {
    $original = new HostedPayment(
        successUrl: 'https://a.test/ok',
        cancelUrl: 'https://a.test/no',
    );
    $restored = HostedPayment::fromPayload($original->toPayload());

    expect($restored->successUrl)->toBe($original->successUrl)
        ->and($restored->cancelUrl)->toBe($original->cancelUrl);
});

it('dispatches to visitHostedPayment on visitor', function () {
    $visitor = new class implements PaymentInstrumentVisitor
    {
        public function visitCreditCard(CreditCard $card): mixed { return 'card'; }
        public function visitCash(Cash $cash): mixed { return 'cash'; }
        public function visitToken(Token $token): mixed { return 'token'; }
        public function visitPaymentMethod(PaymentMethod $paymentMethod): mixed { return 'pm'; }
        public function visitHostedPayment(HostedPayment $hosted): mixed { return 'hosted:'.$hosted->successUrl; }
    };

    $hosted = new HostedPayment(successUrl: 'https://x.test', cancelUrl: 'https://y.test');

    expect($hosted->accept($visitor))->toBe('hosted:https://x.test');
});
