<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;

it('converts Holder to string via __toString', function () {
    expect((string) new Holder('Jane Smith'))->toBe('Jane Smith');
});

it('serializes Holder to JSON via jsonSerialize', function () {
    $holder = new Holder('John Doe');

    expect($holder->jsonSerialize())->toBe('John Doe')
        ->and(json_encode($holder))->toBe('"John Doe"');
});
