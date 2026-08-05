<?php

declare(strict_types=1);

namespace Techork\PaymentService\Neutrino;

use Override;
use Techork\PaymentService\Common\Contract\FactSupplier;
use Throwable;

/**
 * Exposes BIN intelligence as firewall facts under `payment_method.source`,
 * enriching what the caller already knows about the card (its BIN, brand and
 * last four) with what the issuer's range says about it.
 *
 * These are the facts rules are usually written against — issuer country,
 * funding type, whether the card is prepaid or commercial — because they
 * describe the instrument rather than the transaction, so they behave the same
 * whether inspected at registration or at authorization.
 *
 * A lookup failure yields no facts rather than an exception, matching the
 * fail-soft `null` contract of {@see CardIntelligenceProvider}: the chain then
 * evaluates without them and rules referencing them do not match. Nothing is
 * emitted at all in that case, so an absent lookup is distinguishable from a
 * lookup that genuinely reported "not prepaid".
 */
final readonly class NeutrinoCardFactSupplier implements FactSupplier
{
    public function __construct(
        private CardIntelligenceProvider $cards,
        private string                   $bin,
        private ?string                  $ip = null,
    ) {}

    #[Override]
    public function facts(): array
    {
        try {
            $card = $this->cards->lookupBin($this->bin, $this->ip);
        } catch (Throwable) {
            $card = null;
        }

        if ($card === null) {
            return [];
        }

        return [
            'payment_method' => [
                'source' => [
                    'issuer_country' => $card->issuerCountry !== null ? (string) $card->issuerCountry : null,
                    'funding' => $card->funding->value,
                    'is_prepaid' => $card->isPrepaid,
                    'is_commercial' => $card->isCommercial,
                ],
            ],
        ];
    }
}
