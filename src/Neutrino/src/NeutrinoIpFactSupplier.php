<?php

declare(strict_types=1);

namespace Techork\PaymentService\Neutrino;

use Techork\PaymentService\Common\Contract\FactSupplier;
use Throwable;

/**
 * Exposes IP intelligence as firewall facts under `payment_method.connection`:
 * where the request appears to originate and whether it arrives through a proxy
 * or VPN.
 *
 * `host_domain` is included because it is often the more actionable signal — a
 * known hosting provider says more than the proxy flag alone — and it was
 * previously dropped on the floor by the caller.
 *
 * A lookup failure yields no facts rather than an exception, matching the
 * fail-soft `null` contract of {@see IpIntelligenceProvider}. Emitting nothing
 * keeps an absent lookup distinguishable from a lookup that reported "not a
 * proxy": rules referencing these facts simply do not match, instead of matching
 * on a fabricated false.
 */
final class NeutrinoIpFactSupplier implements FactSupplier
{
    public function __construct(
        private readonly IpIntelligenceProvider $ips,
        private readonly string $ip,
    ) {}

    public function facts(): array
    {
        try {
            $connection = $this->ips->lookupIp($this->ip);
        } catch (Throwable) {
            $connection = null;
        }

        if ($connection === null) {
            return [];
        }

        return [
            'payment_method' => [
                'connection' => [
                    'country' => $connection->country !== null ? (string) $connection->country : null,
                    'is_proxy' => $connection->isProxy,
                    'is_vpn' => $connection->isVpn,
                    'host_domain' => $connection->hostDomain,
                ],
            ],
        ];
    }
}
