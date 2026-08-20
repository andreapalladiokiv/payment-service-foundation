<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

/**
 * A gateway that has a customer of its own, and so needs to be told about ours.
 *
 * Narrow on purpose. Its predecessor, `setCustomerRepository()`, sat on {@see Gateway} itself, so
 * all five gateways declared it and three never used it: ConnexPay injected it into every request
 * unread, Paynet and Revolut only satisfied the signature. A gateway with no customer concept
 * should not have to say it has one.
 *
 * {@see \Techork\PaymentService\Gateway\GatewayFactory} hands these over only to gateways that
 * ask, which is what lets the method on `Gateway` be retired.
 */
interface ResolvesGatewayCustomers
{
    public function setGatewayCustomerRepository(GatewayCustomerRepository $repository): void;

    public function setCustomerIdentitySource(CustomerIdentitySource $source): void;
}
