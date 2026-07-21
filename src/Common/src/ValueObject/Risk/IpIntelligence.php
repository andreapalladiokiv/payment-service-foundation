<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Risk;

use Techork\PaymentService\Common\ValueObject\Country;

/**
 * Geolocation / reputation facts about a client IP that fraud rules match on.
 * Produced by an {@see \Techork\PaymentService\Common\Contract\IpIntelligenceProvider}
 * (e.g. Neutrino `ip-info`).
 *
 * `country` is null when the IP could not be geolocated.
 */
final readonly class IpIntelligence
{
    public function __construct(
        public ?Country $country,
        public bool $isProxy = false,
        public bool $isVpn = false,
        public ?string $hostDomain = null,
    ) {}
}
