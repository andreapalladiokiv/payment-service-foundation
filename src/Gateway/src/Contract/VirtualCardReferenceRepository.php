<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Gateway\ValueObject\GatewayId;

interface VirtualCardReferenceRepository
{
    public function find(GatewayId $gatewayId, string $virtualCardId): ?string;

    /**
     * Reverse lookup — gateway-side reference (e.g. ConnexPay `cardGuid`)
     * → our internal `virtual_cards.id`. Returns null if no row matches.
     * Used by webhook handlers that arrive with only the gateway reference.
     */
    public function findVirtualCardId(GatewayId $gatewayId, string $reference): ?string;

    public function saveReference(GatewayId $gatewayId, string $virtualCardId, string $reference): void;
}
