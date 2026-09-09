<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\Customer;
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
     * @param  ?Customer  $customer  Whom the instrument is being kept FOR — which id, who they
     *   are, and where they are billed.
     *
     * Nullable on the type and required in practice for `registerPaymentMethod`, which refuses
     * an absent one with {@see \Techork\PaymentService\Gateway\Exception\RegistrationNeedsCustomer}:
     * storing an instrument for later use is storing it for somebody. Stripe will not make a
     * PaymentMethod reusable without a customer, and Nuvei cannot produce a
     * `userPaymentOptionId` without a `userTokenId`. `tokenize()` genuinely has no customer —
     * a token is one use and then gone — so the field stays nullable rather than being split
     * across two commands.
     *
     * **One field where there were three.** The id, the identity and the address were separate
     * arguments here, and ConnexPay is why they cannot be: every endpoint it has that can make a
     * customer takes a card, so registering the payment method IS registering the customer, and
     * its `Card.Customer` is four person fields AND six AVS fields in one object. Assembling that
     * from three optional arguments is how it came to be built out of `billingAddress` alone — so
     * the person ConnexPay recorded was whoever the card happened to be billed to, and the name
     * and email a merchant actually recorded never reached it. The parts stay distinct *inside*
     * {@see Customer}, which is what keeps an identity from being substituted for an address and
     * quietly ending address verification; what they no longer are is separately omissible.
     *
     * The consequence for `tokenize()`: an address with nobody attached to it is not expressible
     * any more. A one-use token that carries AVS data for a person we cannot name was a shape
     * with no honest caller — the address had to come from somewhere, and what it came from was
     * the payment.
     */
    public function __construct(
        public GatewayId $gatewayId,
        public PaymentInstrument $instrument,
        public ?string $clientUniqueId = null,
        public ?Customer $customer = null,
    ) {}


    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'instrument' => $this->instrument->toPayload(),
            'clientUniqueId' => $this->clientUniqueId,
            'customer' => $this->customer?->toArray(),
        ];
    }
}
