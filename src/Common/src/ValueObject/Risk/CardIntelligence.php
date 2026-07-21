<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\Risk;

use Techork\PaymentService\Common\ValueObject\Country;

/**
 * BIN-derived facts about a card that fraud rules match on — the issuing
 * bank's country and the card's category. Produced by a
 * {@see \Techork\PaymentService\Common\Contract\CardIntelligenceProvider}.
 *
 * None of this is derivable from the BIN digits alone; it requires a BIN
 * reference dataset. `isPrepaid` / `isCommercial` are kept as explicit flags
 * (rather than derived from {@see $funding}) because providers report them
 * independently and either can be unknown while the other is known.
 */
final readonly class CardIntelligence
{
    public function __construct(
        public ?Country $issuerCountry,
        public CardFunding $funding = CardFunding::Unknown,
        public bool $isPrepaid = false,
        public bool $isCommercial = false,
    ) {}
}
