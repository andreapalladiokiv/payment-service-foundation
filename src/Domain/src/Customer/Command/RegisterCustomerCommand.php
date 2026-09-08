<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Command;

use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Domain\Customer\ValueObject\CustomerId;

interface RegisterCustomerCommand
{
    /**
     * The id comes from the caller, as every aggregate id here does.
     *
     * Which person a request belongs to — lookup, deduplication, merging — is the host's
     * policy and deliberately not this aggregate's. It takes the id it is given and refuses
     * to guess.
     */
    public function customerId(): CustomerId;

    public function identity(): CustomerIdentity;
}
