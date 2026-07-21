<?php

declare(strict_types=1);

namespace Techork\PaymentService\Neutrino;

use Techork\PaymentService\Common\Contract\IpIntelligenceProvider;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\Risk\IpIntelligence;
use Throwable;

/**
 * Neutrino `ip-info` implementation of {@see IpIntelligenceProvider}.
 *
 * Fail-soft: returns null on transport failure or an empty response.
 *
 * NOTE: response keys (`country-code`, `is-vpn`, `is-proxy`, `hostname`)
 * follow the Neutrino API and should be re-verified against a live response
 * when credentials are wired; proxy/VPN signals may require the `ip-probe`
 * endpoint on some plans.
 */
final class NeutrinoIpIntelligenceProvider implements IpIntelligenceProvider
{
    public function __construct(private readonly NeutrinoHttpClientInterface $client) {}

    public function lookupIp(string $ip): ?IpIntelligence
    {
        try {
            $data = $this->client->request('ip-info', ['ip' => $ip]);
        } catch (Throwable) {
            return null;
        }

        if ($data === []) {
            return null;
        }

        return new IpIntelligence(
            country: $this->toCountry($data['country-code'] ?? null),
            isProxy: (bool) ($data['is-proxy'] ?? $data['is-hosting'] ?? false),
            isVpn: (bool) ($data['is-vpn'] ?? false),
            hostDomain: $data['hostname'] ?? ($data['host-domain'] ?? null),
        );
    }

    private function toCountry(?string $code): ?Country
    {
        if ($code === null || $code === '') {
            return null;
        }

        try {
            return new Country($code);
        } catch (Throwable) {
            return null;
        }
    }
}
