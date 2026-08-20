<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer\Command;

interface ForgetCustomerCommand
{
    /** Why the erasure was performed — the audit of who asked belongs to the host. */
    public function reason(): string;
}
