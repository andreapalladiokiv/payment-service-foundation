<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Money\Money;
use Techork\PaymentService\Common\Contract\CustomerIdentifier;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * A payment inside a rebilling series — a subscription's first charge, or any renewal.
 *
 * Its own command, not a {@see PlacementCommand} with a flag, because a series payment carries a
 * POSITION and an ordinary one has none. The acquirer needs it: the first payment carries the
 * indicator that opens the chain, every later one the reference back to it.
 *
 * `$genesisReference` is the acquirer's own reference for the payment that opened the series,
 * already resolved by the caller. Null means THIS payment opens it — not that there is no series,
 * which is exactly why it cannot be a nullable field on the general command: nothing about an
 * absent anchor distinguishes the first payment of a subscription from a standalone checkout.
 *
 * `initiation` has no default here, unlike on a placement. A series payment is the case where who
 * initiated it actually varies — a cardholder starting a subscription, the merchant taking a
 * renewal — so defaulting it would let the commonest field in a stored-credential chain be
 * omitted by accident.
 */
final readonly class RebillingCommand
{
    /**
     * @param  ?CustomerIdentifier  $customerId  Whose subscription this renews. Same shape and same reasons
     *   as {@see PlacementCommand}, and it matters more here than anywhere else: Nuvei renews
     *   through a `userPaymentOptionId`, which exists only under the `userTokenId` it was stored
     *   against, so a renewal that names no customer cannot reach the stored instrument at all.
     *   `authorizeRebilling` had no customer and put none in its options while routing through
     *   the same provider call that reads the key.
     */
    public function __construct(
        public GatewayId $gatewayId,
        public PaymentInstrument $instrument,
        public Money $amount,
        public PaymentInitiation $initiation,
        public ?string $genesisReference = null,
        public ?string $clientUniqueId = null,
        public ?BillingAddress $billingAddress = null,
        public ?ThreeDSResult $threeDS = null,
        public ?string $statementDescription = null,
        public ?string $description = null,
        public ?CustomerIdentifier $customerId = null,
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
            'genesisReference' => $this->genesisReference,
            'customerId' => $this->customerId?->toString(),
        ];
    }

    /**
     * The series payment seen as an ordinary placement, for providers that express the position
     * through the initiation alone and have no anchor field to fill.
     *
     * Stripe is the case: its stored-credential handling is driven by `off_session` and the
     * customer the payment method is attached to, so there is nothing for `genesisReference` to
     * land in. Dropping it here is explicit, which is the point — the alternative was one shared
     * request class quietly reading a `rebilling` flag some callers set and others did not.
     */
    public function toPlacement(): PlacementCommand
    {
        return new PlacementCommand(
            gatewayId: $this->gatewayId,
            instrument: $this->instrument,
            amount: $this->amount,
            clientUniqueId: $this->clientUniqueId,
            billingAddress: $this->billingAddress,
            threeDS: $this->threeDS,
            statementDescription: $this->statementDescription,
            description: $this->description,
            initiation: $this->initiation,
            customerId: $this->customerId,
        );
    }
}
