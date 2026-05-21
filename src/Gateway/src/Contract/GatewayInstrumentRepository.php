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
}
