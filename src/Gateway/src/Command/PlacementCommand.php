<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Placing a payment: the same nine fields whether the money is only reserved or taken outright.
 *
 * One command for both, because the difference between them is not in the request — every
 * provider expresses it as a field on the same call (`capture_method` at Stripe,
 * `transactionType` at Nuvei) — but in which operation the caller chose. That choice stays a
 * method rather than becoming a flag here, because it is separately refusable: Paynet takes a
 * payment and has no auth-only product at all.
 *
 * A series payment is the other way round and gets {@see RebillingCommand}: it carries a position
 * this does not have, which makes it a different request rather than the same one with an extra
 * field.
 */
final readonly class PlacementCommand
{
    public function __construct(
        public GatewayId $gatewayId,
        public PaymentInstrument $instrument,
        public Money $amount,
        public ?string $clientUniqueId = null,
        public ?BillingAddress $billingAddress = null,
        public ?ThreeDSResult $threeDS = null,
        public ?string $statementDescription = null,
        public ?string $description = null,
        public PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
    ) {}


    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'amount' => $this->amount,
            'instrument' => $this->instrument->toPayload(),
            'clientUniqueId' => $this->clientUniqueId,
            'billingAddress' => $this->billingAddress?->toArray(),
            'threeDS' => $this->threeDS,
            'statementDescription' => $this->statementDescription,
            'description' => $this->description,
            'initiation' => $this->initiation->value,
        ];
    }
}
