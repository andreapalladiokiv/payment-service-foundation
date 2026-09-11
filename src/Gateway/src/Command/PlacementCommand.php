<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\Customer;
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
     * @param  ?Customer  $customer  Whose payment this is: OUR id for them, who they are, and the
     *   address they are billed at. The id is never derived from an attribute, which is what keeps
     *   it stable when an email changes, and it is a
     *   {@see \Techork\PaymentService\Common\ValueObject\CustomerId} rather than a string so
     *   it does not degrade at a package boundary.
     *
     * The whole customer rather than the id alone, and it is why there is no longer a
     * `billingAddress` beside it. A provider needs all three parts at once — Nuvei's payment body
     * names the payer and their address in one block, ConnexPay's `Card.Customer` in one object —
     * and the mapper used to assemble that out of an id from here and a name read off the address,
     * which is how the address became the de-facto record of who was paying. One field carries
     * both, or the mapper goes on guessing.
     *
     * On the command and not on the credential, because a customer is a fact about THIS payment
     * — which is what separates it from `authenticationUrl` and `returnUrl`, one address per
     * deployment. It sits with `clientUniqueId` and `initiation`, which are per-payment facts too.
     * Optional here: a one-off charge can belong to nobody we have a record of. Registering an
     * instrument is the one operation where it is not
     * ({@see \Techork\PaymentService\Gateway\Exception\RegistrationNeedsCustomer}).
     */
    public function __construct(
        public GatewayId $gatewayId,
        public PaymentInstrument $instrument,
        public Money $amount,
        public ?string $clientUniqueId = null,
        public ?ThreeDSResult $threeDS = null,
        public ?string $statementDescription = null,
        public ?string $description = null,
        public PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
        public ?Customer $customer = null,
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
            'threeDS' => $this->threeDS?->toLogContext(),
            'statementDescription' => $this->statementDescription,
            'description' => $this->description,
            'initiation' => $this->initiation->value,
            'customer' => $this->customer?->toArray(),
        ];
    }
}
