<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Handing an instrument to the provider to keep, either as a one-use token or as a stored
 * payment method.
 *
 * One command for both, because the fields are the same and the difference is in what the
 * provider is asked to keep and for how long — see
 * {@see \Techork\PaymentService\Gateway\Role\VaultsInstruments} for why they stay separate
 * operations.
 */
final readonly class VaultCommand
{
    public function __construct(
        public GatewayId $gatewayId,
        public PaymentInstrument $instrument,
        public ?BillingAddress $billingAddress = null,
        public ?string $clientUniqueId = null,
    ) {}


    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'instrument' => $this->instrument->toPayload(),
            'billingAddress' => $this->billingAddress?->toArray(),
            'clientUniqueId' => $this->clientUniqueId,
        ];
    }
}
