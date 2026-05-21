<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\TokenId;

it('generates a valid uuid')
    ->expect(TokenId::generate())
    ->toString()->toBeUuid();

it('creates from string')
    ->expect($original = TokenId::generate())
    ->toString()->toBe(TokenId::fromString($original->toString())->toString());

it('converts to string')
    ->expect(TokenId::fromString('01942f6e-1c3a-7b8d-9e4f-123456789abc'))
    ->toString()->toBe('01942f6e-1c3a-7b8d-9e4f-123456789abc');

it('equals another TokenId with the same value')
    ->expect(TokenId::fromString('01942f6e-1c3a-7b8d-9e4f-123456789abc'))
    ->equals(TokenId::fromString('01942f6e-1c3a-7b8d-9e4f-123456789abc'))
    ->toBeTrue();

it('does not equal a different TokenId')
    ->expect(TokenId::generate())
    ->equals(TokenId::generate())
    ->toBeFalse();

it('throws on invalid uuid string')
    ->expect(fn () => TokenId::fromString('not-a-uuid'))
    ->throws(InvalidArgumentException::class);
