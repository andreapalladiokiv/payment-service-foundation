<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;

function makeCardSummary(string $bin = '411111', string $last4 = '1111'): CardSummary
{
    return new CardSummary(
        bin: $bin,
        last4: $last4,
        brand: CardBrand::Visa,
        expiration: Expiration::fromMonthAndYear(12, 2030),
        holder: new Holder('John Doe'),
    );
}

it('holds the PCI-safe card projection', function () {
    $card = makeCardSummary();

    expect($card->bin)->toBe('411111')
        ->and($card->last4)->toBe('1111')
        ->and($card->brand)->toBe(CardBrand::Visa)
        ->and((string) $card->holder)->toBe('John Doe');
});

it('rejects a BIN that is not exactly six digits', function (string $bin) {
    makeCardSummary(bin: $bin);
})->with(['41111', '4111111', '41111a', ''])->throws(InvalidArgumentException::class);

it('rejects a last-four that is not exactly four digits', function (string $last4) {
    makeCardSummary(last4: $last4);
})->with(['111', '11111', '11a1', ''])->throws(InvalidArgumentException::class);
