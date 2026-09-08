<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\CustomerIdentifier;

/**
 * A customer id this package can hold without being able to make one.
 *
 * Deliberately NOT `Domain\Customer\ValueObject\CustomerId`: Gateway depends on `Common` and
 * `Gateway` and must never load the domain, which is the property
 * {@see \Techork\PaymentService\Common\Contract\CustomerIdentifier} exists to give — an
 * adapter names the customer's identity, and cannot mint one. A fake here is that constraint
 * holding rather than a shortcut around it.
 */
function gatewaySuiteCustomerId(string $id = '01920000-0000-7000-8000-00000000cafe'): CustomerIdentifier
{
    /**
     * One instance per id, so a test may compare by identity as well as by value — a fresh object
     * each call would make `toBe` fail on the same customer.
     *
     * @var array<string, CustomerIdentifier>
     */
    static $minted = [];

    return $minted[$id] ??= new readonly class($id) implements CustomerIdentifier
    {
        public function __construct(private string $id) {}

        public function toString(): string
        {
            return $this->id;
        }

        public function __toString(): string
        {
            return $this->id;
        }
    };
}
