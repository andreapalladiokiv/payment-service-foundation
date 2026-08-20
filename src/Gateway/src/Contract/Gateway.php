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
    /**
     * Omnipay keeps this on `AbstractGateway` rather than on `GatewayInterface`, which this
     * contract extends — so callers that legitimately need it had no declared way to reach
     * it. {@see \Techork\PaymentService\Laravel\LaravelGatewayFactory} applies the
     * infrastructure defaults from `services.{gateway}` through it. Deliberately untyped,
     * matching the implementation it names; adding types here would be incompatible with it.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function setParameter($key, $value);

    public function createPaymentMethod(array $options = []): RequestInterface;

    public function void(array $options = []): RequestInterface;

    public function issueVirtualCard(array $options = []): RequestInterface;

    public function terminateVirtualCard(array $options = []): RequestInterface;

    /**
     * Step 2 of a refund: send the money to a different card after the original one
     * declined it.
     *
     * In the contract because {@see \Techork\PaymentService\Gateway\PaymentGatewayRouter}
     * calls it on whatever gateway it holds, and only three of five providers had it.
     * The router's catch turned the resulting `Call to undefined method` into a failed
     * result, so the merchant was handed a PHP error string as the reason a refund did not
     * go through. Declared here, a provider without the primitive has to refuse the way it
     * refuses everything else it cannot do.
     */
    public function retryRefund(array $options = []): RequestInterface;

    /**
     * Adjust a live virtual card. Declared for the same reason as {@see self::retryRefund()}.
     */
    public function updateVirtualCard(array $options = []): RequestInterface;
}
