<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Omnipay\Common\GatewayInterface;
use Omnipay\Common\Message\RequestInterface;

/**
 * All payment gateways must implement this interface.
 * Extends Omnipay's GatewayInterface with our domain-specific capabilities.
 */
interface Gateway extends GatewayInterface
{
    public function setCustomerRepository(CustomerRepository $repository): void;

    public function createPaymentMethod(array $options = []): RequestInterface;

    public function void(array $options = []): RequestInterface;

    public function issueVirtualCard(array $options = []): RequestInterface;

    public function terminateVirtualCard(array $options = []): RequestInterface;
}
