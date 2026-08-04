<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

interface GatewayInstrumentRepository
{
    public function find(GatewayId $gatewayId, PaymentInstrument $instrument): ?string;

    public function saveReference(GatewayId $gatewayId, PaymentInstrument $instrument, string $reference): void;

    public function saveFailure(GatewayId $gatewayId, PaymentInstrument $instrument, string $reason): void;

    /**
     * The transaction that established this instrument's stored credential, as
     * surfaced by {@see RegistrationResult::$storedCredentialReference}.
     *
     * A second, typed pair rather than a free-form metadata bag on purpose: a
     * subsequent merchant-initiated payment has to quote this value back, so the
     * adapter that reads it and the caller that wrote it must agree on it exactly.
     * Agreement on a key string is the kind that fails silently — the lookup finds
     * nothing, the chain goes unanchored, and the payment still succeeds.
     *
     * Null where the registration began no chain: see
     * {@see StoredCredentialReferenceProvider} for when that happens.
     */
    public function findStoredCredentialReference(GatewayId $gatewayId, PaymentInstrument $instrument): ?string;

    public function saveStoredCredentialReference(GatewayId $gatewayId, PaymentInstrument $instrument, string $reference): void;
}
