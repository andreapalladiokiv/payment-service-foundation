<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use InvalidArgumentException;
use Stringable;

/**
 * A validated IPv4 or IPv6 address. Wrapping the raw string keeps an invalid
 * address from ever reaching fraud screening or a gateway's device details.
 */
final readonly class IpAddress implements Stringable
{
    public function __construct(public string $value)
    {
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("Invalid IP address: {$value}");
        }
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
