<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway;

use Omnipay\Common\GatewayFactory as OmnipayGatewayFactory;
use RuntimeException;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;

/**
 * Extends Omnipay's gateway factory with credential-based resolution.
 *
 * The registry maps gateway name → provider class. Each
 * registered class must implement {@see Gateway} — which extends
 * Omnipay's interface with our domain-level methods.
 *
 * Instances are cached per credential to avoid re-initialising on every
 * call inside a single request.
 */
class GatewayFactory extends OmnipayGatewayFactory
{
    /** @var array<string, Gateway> */
    private array $instances = [];

    public function __construct(private readonly CustomerRepository $repository)
    {
    }

    public function createForCredential(GatewayCredential $credential): Gateway
    {
        $key = $credential->getId()->toString();

        if (! isset($this->instances[$key])) {
            $class = $this->all()[$credential->getGatewayName()]
                ?? throw new RuntimeException("Gateway '{$credential->getGatewayName()}' is not registered.");

            if (! class_exists($class)) {
                throw new RuntimeException("Class '{$class}' not found.");
            }

            if (! is_a($class, Gateway::class, true)) {
                throw new RuntimeException("Gateway '{$class}' must implement ".Gateway::class.'.');
            }

            $gateway = $this->instantiate($class);
            $gateway->initialize($credential->getCredentials());
            $gateway->setCustomerRepository($this->repository);

            $this->instances[$key] = $gateway;
        }

        return $this->instances[$key];
    }

    /**
     * Instantiates a gateway class. Overridden by the Laravel bridge to
     * resolve via the container so provider gateways can receive repository
     * dependencies through their constructor.
     */
    /**
     * @param class-string<Gateway> $class every caller proves this with `is_a()` first, so
     *   stating it here is what lets `new $class` be a Gateway rather than a bare object
     */
    protected function instantiate(string $class): Gateway
    {
        return new $class;
    }
}
