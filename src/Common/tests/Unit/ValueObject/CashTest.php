<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;

it('has TYPE constant set to cash', function () {
    expect(Cash::type())->toBe('cash');
});

it('is always valid', function () {
    expect((new Cash)->isValid())->toBeTrue();
});

it('serializes to payload', function () {
    expect((new Cash)->toPayload())->toBe(['type' => 'cash']);
});

it('deserializes from payload', function () {
    $cash = Cash::fromPayload(['type' => 'cash']);

    expect($cash)->toBeInstanceOf(Cash::class)
        ->and($cash->isValid())->toBeTrue();
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
        public function visitHostedPayment(HostedPayment $hosted): string
        { return 'hosted'; }
    };

    expect((new Cash)->accept($visitor))->toBe('cash');
});
