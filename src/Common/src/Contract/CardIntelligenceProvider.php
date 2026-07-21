<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

use Techork\PaymentService\Common\ValueObject\Risk\CardIntelligence;

/**
 * Resolves BIN-derived facts about a card (issuing country, funding, prepaid /
 * commercial flags) that fraud rules match on. Implemented by the Neutrino
 * sub-package (`bin-lookup`).
 *
 * Returns null when the BIN cannot be resolved — the lookup is fail-soft, so a
 * provider outage degrades rules gracefully instead of blocking the flow.
 * Caching and staleness are the concern of a decorating implementation in the
 * consuming application, not of this contract.
 *
 * @param string $bin The card BIN (first six digits).
 * @param string|null $ip Optional client IP some providers use to refine the lookup.
 */
interface CardIntelligenceProvider
{
    public function lookupBin(string $bin, ?string $ip = null): ?CardIntelligence;
}
