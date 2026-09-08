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
    /**
     * @param  ?CustomerIdentifier  $customerId  Whose payment this is — OUR id for them, minted by us and
     *   never derived from an attribute, which is what keeps it stable when an email changes.
     *   A {@see CustomerIdentifier} and not a string: there is a value object for this identity,
     *   so it does not degrade at a package boundary. The interface lives in `Common` and its one
     *   implementation is the aggregate's `CustomerId`, which is what lets this package name the
     *   customer without being able to load a domain type — or to invent one.
     *
     * On the command and not on the credential, because a customer is a fact about THIS payment
     * — which is what separates it from `authenticationUrl` and `returnUrl`, one address per
     * deployment. It sits with `clientUniqueId`, `billingAddress` and `initiation`, which are
     * per-payment facts too. Optional here: a one-off charge can belong to nobody we have a
     * record of. Registering an instrument is the one operation where it is not
     * ({@see \Techork\PaymentService\Gateway\Exception\RegistrationNeedsCustomer}).
     */
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
            'customerId' => $this->customerId?->toString(),
        ];
    }
}
