<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use Techork\PaymentService\Common\Pii;
use Techork\PaymentService\Common\ShreddingStubs;

/**
 * Who a customer is, as a payment provider needs to be told.
 *
 * In `Common` rather than in the customer's own package because provider packages read it
 * directly to build a provider-side customer, the way they already read {@see BillingAddress}
 * in `formatRiskData()`. It is data, not an aggregate id.
 *
 * **The email is not the identity.** It is a field here and nothing more: two customers may
 * share one, and changing it changes nothing about which customer this is. That is worth
 * stating in the type because the opposite is currently built into an adapter —
 * `Nuvei\CreateCustomerRequest` sends the email as `userTokenId`, the id Nuvei documents as
 * uniquely identifying a consumer, so a change there orphans every card stored against the
 * old value.
 *
 * Every field is optional except the name, and the name only because both Nuvei and Stripe
 * ask for one; a placeholder is the honest answer when it is genuinely unknown, which is what
 * {@see ShreddingStubs::NAME} is for. Making any of the others required would put us back
 * where we are: an optional fact deciding whether a customer may exist at all.
 */
final readonly class CustomerIdentity
{
    public function __construct(
        #[Pii(ShreddingStubs::NAME)] public string $firstName,
        #[Pii(ShreddingStubs::NAME)] public string $lastName,
        #[Pii(new Email(ShreddingStubs::EMAIL))] public ?Email $email = null,
        #[Pii(new PhoneNumber(ShreddingStubs::PHONE))] public ?PhoneNumber $phone = null,
    ) {}

    /**
     * The identity of a customer we have been asked to forget.
     *
     * Every field is the stub for its type, which is the same marker a shredded payment
     * carries — so "we deleted this" and "we never had this" read identically downstream,
     * which is correct: in both cases there is nothing to show anyone.
     */
    public static function forgotten(): self
    {
        return new self(ShreddingStubs::NAME, ShreddingStubs::NAME);
    }

    /**
     * The identity a payment method's own billing address carries.
     *
     * Every field a {@see CustomerIdentity} has is already on a {@see BillingAddress}, because
     * until now the address *was* where the payer's name and email were kept — one copy per
     * card. That is what makes backfilling a customer onto an existing payment method possible
     * at all: a payment method nobody has ever named a customer for still knows who paid with
     * it. The backfill itself is the host's (A2 in `docs/customer-domain-plan`); this is the
     * reading it needs, here rather than there because both types are.
     *
     * Not a general-purpose conversion. It is the honest reading of an address as an identity
     * and nothing more, so a stubbed address yields a stubbed identity rather than being
     * refused — `ShreddingStubs::NAME` is a name here for the same reason it is one in
     * {@see forgotten()}.
     */
    public static function fromBillingAddress(BillingAddress $address): self
    {
        return new self(
            firstName: $address->firstName,
            lastName: $address->lastName,
            email: $address->email,
            phone: $address->phone,
        );
    }

    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email ? (string) $this->email : null,
            'phone' => $this->phone ? (string) $this->phone : null,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            email: ! empty($data['email']) ? new Email($data['email']) : null,
            phone: ! empty($data['phone']) ? new PhoneNumber($data['phone']) : null,
        );
    }
}
