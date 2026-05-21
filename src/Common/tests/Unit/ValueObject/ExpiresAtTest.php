<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\ExpiresAt;

// ──────────────────────────────────────────────
//  fromDateTime
// ──────────────────────────────────────────────

it('creates from DateTimeInterface', function () {
    $dt = new DateTimeImmutable('2026-06-15T10:30:00+00:00');
    $expiresAt = ExpiresAt::fromDateTime($dt);

    expect($expiresAt->toDateTime()->format('Y-m-d H:i:s'))->toBe('2026-06-15 10:30:00');
});

it('creates from mutable DateTime', function () {
    $dt = new DateTime('2026-08-20T14:00:00+00:00');
    $expiresAt = ExpiresAt::fromDateTime($dt);

    expect($expiresAt->toDateTime())->toBeInstanceOf(DateTimeImmutable::class)
        ->and($expiresAt->toDateTime()->format('Y-m-d'))->toBe('2026-08-20');
});

// ──────────────────────────────────────────────
//  fromString
// ──────────────────────────────────────────────

it('creates from valid ATOM string', function () {
    $str = '2026-12-31T23:59:59+00:00';
    $expiresAt = ExpiresAt::fromString($str);

    expect($expiresAt->toString())->toBe($str);
});

it('throws on invalid string format', function () {
    ExpiresAt::fromString('not-a-date');
})->throws(InvalidArgumentException::class, 'Invalid expiresAt format');

it('throws on empty string', function () {
    ExpiresAt::fromString('');
})->throws(InvalidArgumentException::class, 'Invalid expiresAt format');

// ──────────────────────────────────────────────
//  isExpired
// ──────────────────────────────────────────────

it('reports as expired when date is in the past', function () {
    $expiresAt = ExpiresAt::fromDateTime(new DateTimeImmutable('-1 day'));

    expect($expiresAt->isExpired())->toBeTrue();
});

it('reports as not expired when date is in the future', function () {
    $expiresAt = ExpiresAt::fromDateTime(new DateTimeImmutable('+1 day'));

    expect($expiresAt->isExpired())->toBeFalse();
});

it('reports as expired when now equals expiry', function () {
    $now = new DateTimeImmutable;
    $expiresAt = ExpiresAt::fromDateTime($now);

    expect($expiresAt->isExpired($now))->toBeTrue();
});

it('uses custom now for expiry check', function () {
    $expiresAt = ExpiresAt::fromDateTime(new DateTimeImmutable('2026-06-01T12:00:00+00:00'));
    $beforeExpiry = new DateTimeImmutable('2026-06-01T11:59:59+00:00');
    $afterExpiry = new DateTimeImmutable('2026-06-01T12:00:01+00:00');

    expect($expiresAt->isExpired($beforeExpiry))->toBeFalse()
        ->and($expiresAt->isExpired($afterExpiry))->toBeTrue();
});

// ──────────────────────────────────────────────
//  toString / toPayload / fromPayload
// ──────────────────────────────────────────────

it('toString returns ATOM formatted string', function () {
    $str = '2026-03-15T08:30:00+00:00';
    $expiresAt = ExpiresAt::fromString($str);

    expect($expiresAt->toString())->toBe($str);
});

it('toPayload returns same as toString', function () {
    $str = '2026-09-01T00:00:00+00:00';
    $expiresAt = ExpiresAt::fromString($str);

    expect($expiresAt->toPayload())->toBe($expiresAt->toString());
});

it('fromPayload creates from payload string', function () {
    $str = '2026-11-25T18:45:00+00:00';
    $expiresAt = ExpiresAt::fromPayload($str);

    expect($expiresAt->toString())->toBe($str);
});

it('survives toPayload/fromPayload roundtrip', function () {
    $original = ExpiresAt::fromDateTime(new DateTimeImmutable('2027-01-01T00:00:00+00:00'));
    $restored = ExpiresAt::fromPayload($original->toPayload());

    expect($restored->toString())->toBe($original->toString())
        ->and($restored->toDateTime()->getTimestamp())->toBe($original->toDateTime()->getTimestamp());
});
