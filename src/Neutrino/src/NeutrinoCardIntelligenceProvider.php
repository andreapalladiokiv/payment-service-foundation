<?php

declare(strict_types=1);

namespace Techork\PaymentService\Neutrino;

use Override;
use Techork\PaymentService\Common\ValueObject\Country;
use Throwable;

/**
 * Neutrino `bin-lookup` implementation of {@see CardIntelligenceProvider}.
 *
 * Fail-soft: returns null on transport failure or an empty response so the
 * rule engine degrades gracefully. Neutrino's `bin-lookup` reports issuing
 * country plus prepaid / commercial flags but not a credit/debit funding
 * type, so {@see CardIntelligence::$funding} stays {@see CardFunding::Unknown}.
 *
 * NOTE: response keys (`country-code`, `is-prepaid`, `is-commercial`) follow
 * the Neutrino v3 API and should be re-verified against a live response when
 * credentials are wired.
 */
final readonly class NeutrinoCardIntelligenceProvider implements CardIntelligenceProvider
{
    public function __construct(private NeutrinoHttpClientInterface $client) {}

    #[Override]
    public function lookupBin(string $bin, ?string $ip = null): ?CardIntelligence
    {
        $params = ['bin-number' => $bin];

        if ($ip !== null) {
            $params['customer-ip'] = $ip;
        }

        try {
            $data = $this->client->request('bin-lookup', $params);
        } catch (Throwable) {
            return null;
        }

        if ($data === []) {
            return null;
        }

        return new CardIntelligence(
            issuerCountry: $this->toCountry($data['country-code'] ?? null),
            funding: CardFunding::Unknown,
            isPrepaid: (bool) ($data['is-prepaid'] ?? false),
            isCommercial: (bool) ($data['is-commercial'] ?? false),
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
