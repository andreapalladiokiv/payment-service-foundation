<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\PaymentMethodId;

it('generates a valid uuid')
    ->expect(PaymentMethodId::generate())
    ->toString()->toBeUuid();

it('creates from string')
    ->expect($original = PaymentMethodId::generate())
    ->toString()->toBe(PaymentMethodId::fromString($original->toString())->toString());

it('converts to string')
    ->expect(PaymentMethodId::fromString('01942f6e-1c3a-7b8d-9e4f-123456789abc'))
    ->toString()->toBe('01942f6e-1c3a-7b8d-9e4f-123456789abc');

it('equals another PaymentMethodId with the same value')
    ->expect(PaymentMethodId::fromString('01942f6e-1c3a-7b8d-9e4f-123456789abc'))
    ->equals(PaymentMethodId::fromString('01942f6e-1c3a-7b8d-9e4f-123456789abc'))
    ->toBeTrue();

it('does not equal a different PaymentMethodId')
    ->expect(PaymentMethodId::generate())
    ->equals(PaymentMethodId::generate())
    ->toBeFalse();

it('throws on invalid uuid string')
    ->expect(fn () => PaymentMethodId::fromString('not-a-uuid'))
    ->throws(InvalidArgumentException::class);
