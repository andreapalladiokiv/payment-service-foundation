<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use Techork\PaymentService\Common\Pii;
use Techork\PaymentService\Common\ShreddingStubs;

/**
 * WHO a customer is, as a payment provider needs to be told — their name, email and phone. Its
 * neighbour {@see CustomerId} is the other half: WHICH customer this is. This one can be
 * corrected, erased or absent; that one cannot.
 * {@see Customer} is the pair of them plus the address, and it is what actually crosses a
 * boundary — this type is rarely handled alone.
 *
 * In `Common` because provider packages read it directly to build a provider-side customer, the
 * way they already read {@see BillingAddress} in `formatRiskData()`. It is data, not an id.
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
