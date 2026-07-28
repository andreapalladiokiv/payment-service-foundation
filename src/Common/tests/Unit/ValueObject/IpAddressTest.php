<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\IpAddress;

it('accepts a valid IPv4 address', function () {
    expect(new IpAddress('203.0.113.7')->toString())->toBe('203.0.113.7');
});

it('accepts a valid IPv6 address', function () {
    expect((string) new IpAddress('2001:db8::1'))->toBe('2001:db8::1');
});

it('rejects an invalid IP address', function () {
    new IpAddress('not-an-ip');
})->throws(InvalidArgumentException::class, 'Invalid IP address: not-an-ip');
