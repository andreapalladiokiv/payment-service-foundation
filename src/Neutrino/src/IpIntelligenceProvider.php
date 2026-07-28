<?php

declare(strict_types=1);

namespace Techork\PaymentService\Neutrino;


/**
 * Resolves geolocation / reputation facts about a client IP (country, proxy /
 * VPN flags) that fraud rules match on. Implemented by the Neutrino
 * sub-package (`ip-info`).
 *
 * Returns null when the IP cannot be resolved — fail-soft, like
 * {@see CardIntelligenceProvider}. Caching lives in a decorating
 * implementation in the consuming application.
 */
interface IpIntelligenceProvider
{
    public function lookupIp(string $ip): ?IpIntelligence;
}
