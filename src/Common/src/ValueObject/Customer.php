<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use libphonenumber\NumberParseException;
use Techork\PaymentService\Common\ValueObject\Customer;

/**
 * The payer, whole: WHICH customer, WHO they are, and WHERE they are billed.
 *
 * These three travelled separately and the seams were where things went wrong. A
 * {@see BillingAddress} used to carry the payer's name, email and phone as well as their
 * address — one copy per card — so every provider read the person off whatever address rode
 * along with the payment, and `Nuvei\CreateCustomerRequest` went as far as keying its customer on
 * that email. Meanwhile the id travelled as a fourth argument that a caller could simply not
 * pass. Anything that needs one of the three needs the others: this is the unit that crosses a
 * boundary, so an incomplete payer is no longer expressible.
 *
 * **The three parts are not interchangeable, which is why they stay three fields.** ConnexPay's
 * `Card.Customer` is the case that proves it: four person fields AND six AVS fields in one
 * object, so folding an identity into an address registers the right person and silently ends
 * address verification. A provider mapper reads `identity` for who and `billingAddress` for
 * where, and the two are never substituted for each other.
 *
 * The id is a {@see CustomerId} — one concrete type, which every boundary here speaks and
 * {@see fromArray()} can rebuild. It was an interface for a while, on the argument that an
 * application should be able to hand its own id shape across; what that bought was a second name
 * for one thing and a normalizer whose only job was to turn an uninstantiable interface back into
 * this class. An application keyed on something else maps it to one of these at its own edge.
 *
 * **Every part is required, the address included.** A customer with no address was expressible
 * for about as long as it took to notice what it meant: a payer nobody can verify, at every
 * provider that runs AVS, arriving as an ordinary customer. Where the address is genuinely
 * unknown it is {@see BillingAddress::unknown()} — `ZZ` and the shredding stubs, which reads as
 * "no data" to a mapper and to a report, rather than a null that reads as "this one is exempt".
 * The identity is required for the same reason and answers it the same way, with
 * `ShreddingStubs::NAME` for a name nobody recorded.
 *
 * The consequence worth stating: **naming a customer is a deliberate act**, because there is
 * nothing partial to reach for. A call with no payer to name passes no customer at all — a state
 * every mapper here already handles — instead of one assembled out of whatever fragments were to
 * hand, which is precisely how a provider-side customer used to be minted from a billing address.
 */
final readonly class Customer
{
    public function __construct(
        public CustomerId $id,
        public CustomerIdentity $identity,
        public BillingAddress $billingAddress,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'identity' => $this->identity->toArray(),
            'billing_address' => $this->billingAddress->toArray(),
        ];
    }

    /**
     * @throws NumberParseException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: CustomerId::fromString($data['id']),
            identity: CustomerIdentity::fromArray($data['identity']),
            billingAddress: BillingAddress::fromArray($data['billing_address']),
        );
    }
}
