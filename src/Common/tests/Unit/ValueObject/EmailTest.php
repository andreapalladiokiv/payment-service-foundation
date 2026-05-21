<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Email;

it('creates Email from valid address', function () {
    $email = new Email('user@example.com');

    expect((string) $email)->toBe('user@example.com');
});

it('throws RuntimeException for invalid email address', function () {
    new Email('not-an-email');
})->throws(RuntimeException::class, 'Invalid email');

it('serializes Email to JSON via jsonSerialize', function () {
    $email = new Email('test@example.com');

    expect($email->jsonSerialize())->toBe('test@example.com')
        ->and(json_encode($email))->toBe('"test@example.com"');
});
