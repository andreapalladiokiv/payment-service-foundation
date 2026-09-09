<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Ask a provider to hold a customer of ours, from the customer and nothing else — no card.
 *
 * The customer has a lifecycle of its own, kept by the application, so registering it at a
 * provider is an operation of its own — performed deliberately by whoever holds the customer,
 * rather than happening as a side effect of attaching a card. That is the whole point of the command existing: the two
 * adapters that could create a provider-side customer did it from inside
 * `resolveCustomerReference()`, which was lookup-**or-create** and hung on `charge` and
 * `authorize` as well as on the registration. So taking a payment could mint a customer that
 * cannot possibly own the instrument being charged — an attached instrument belongs to the
 * customer it was attached to, so Stripe refuses the pair and Nuvei never finds the stored
 * option — and what was left behind was a stray customer and a failed payment.
 *
 * `$customer` is not optional and neither is any part of it. The id is ours, and being a
 * {@see \Techork\PaymentService\Common\ValueObject\CustomerId} rather than a nullable string,
 * an absent one is not expressible here at all. That is the case a nullable field used to swallow,
 * and what a default would have reached for is the email; an email-keyed provider customer is
 * exactly the state this removes.
 *
 * Inside it the address stays separate from the identity, because at more than one provider a
 * single object carries both and they are not interchangeable: ConnexPay's `Card.Customer` holds
 * four person fields AND six AVS fields, so substituting an identity for an address would quietly
 * end address verification.
 */
final readonly class RegisterCustomerCommand
{
    public function __construct(
        public GatewayId $gatewayId,
        public Customer $customer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'gatewayId' => $this->gatewayId->toString(),
            'customer' => $this->customer->toArray(),
        ];
    }
}
