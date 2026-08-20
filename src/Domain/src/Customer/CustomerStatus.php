<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer;

enum CustomerStatus: string
{
    case Active = 'active';

    /**
     * Erased at their request. Terminal: the identity is gone and cannot be given back, because
     * this aggregate cannot tell whether a new one belongs to the same person — that judgement
     * is the host's.
     *
     * A state rather than the absence of one, because it cannot be read off the identity: the
     * stubs a forgotten customer carries are deliberately the same ones a customer whose details
     * we never had carries, so "we deleted this" and "we never had this" are indistinguishable by
     * design. See {@see \Techork\PaymentService\Common\ValueObject\BillingAddress::unknown()}.
     */
    case Forgotten = 'forgotten';
}
