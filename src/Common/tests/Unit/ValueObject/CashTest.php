<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
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
        public function visitCreditCard(CreditCard $card): mixed { return 'card'; }
        public function visitCash(Cash $cash): mixed { return 'cash'; }
        public function visitToken(Token $token): mixed { return 'token'; }
        public function visitPaymentMethod(PaymentMethod $paymentMethod): mixed { return 'pm'; }
        public function visitHostedPayment(\Techork\PaymentService\Common\ValueObject\HostedPayment $hosted): mixed { return 'hosted'; }
    };

    expect((new Cash)->accept($visitor))->toBe('cash');
});
