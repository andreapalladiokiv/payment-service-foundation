<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;

it('constructs Expiration from DateTimeInterface', function () {
    $exp = new Expiration(new DateTimeImmutable('2030-12-15T12:00:00+00:00'));

    expect((string) $exp)->toBe('1230');
});

it('creates Expiration from month and short year', function () {
    expect((string) Expiration::fromMonthAndYear(3, 30))->toBe('0330');
});

it('creates Expiration from month and full year', function () {
    expect((string) Expiration::fromMonthAndYear(12, 2030))->toBe('1230');
});

it('reports Expiration not expired for future date', function () {
    expect(Expiration::fromMonthAndYear(12, 2030)->expired())->toBeFalse();
});

it('reports Expiration expired for past date', function () {
    expect(Expiration::fromMonthAndYear(1, 2020)->expired())->toBeTrue();
});

it('formats Expiration with custom format string', function () {
    expect(Expiration::fromMonthAndYear(12, 2030)->format('m/Y'))->toBe('12/2030');
});

it('serializes Expiration to JSON as my format via jsonSerialize', function () {
    $exp = Expiration::fromMonthAndYear(6, 2028);

    expect($exp->jsonSerialize())->toBe('0628')
        ->and(json_encode($exp))->toBe('"0628"');
});
