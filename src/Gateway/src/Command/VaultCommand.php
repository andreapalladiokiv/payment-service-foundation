<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Techork\PaymentService\Common\Contract\CustomerIdentifier;
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
    /**
     * @param  ?CustomerIdentifier  $customerId  Whom the instrument is being kept FOR.
     *
     * Nullable on the type and required in practice for `registerPaymentMethod`, which refuses
     * an absent one with {@see \Techork\PaymentService\Gateway\Exception\RegistrationNeedsCustomer}:
     * storing an instrument for later use is storing it for somebody. Stripe will not make a
     * PaymentMethod reusable without a customer, and Nuvei cannot produce a
     * `userPaymentOptionId` without a `userTokenId`. `tokenize()` genuinely has no customer —
     * a token is one use and then gone, which is the same reason the aggregate will not hold
     * one — so the field stays nullable rather than being split across two commands.
     */
    public function __construct(
        public GatewayId $gatewayId,
        public PaymentInstrument $instrument,
        public ?BillingAddress $billingAddress = null,
        public ?string $clientUniqueId = null,
        public ?CustomerIdentifier $customerId = null,
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
            'customerId' => $this->customerId?->toString(),
        ];
    }
}
