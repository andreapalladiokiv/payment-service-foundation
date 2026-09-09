<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use Techork\PaymentService\Common\Pii;
use Techork\PaymentService\Common\ShreddingStubs;

/**
 * WHERE a payment is billed, and nothing else.
 *
 * It used to carry the payer's name, email and phone as well, which made it the de-facto record
 * of who was paying — one copy per card, corrected nowhere, and read as an identity by every
 * provider mapper in the tree. Those four fields are {@see CustomerIdentity}'s, and both halves
 * now travel together inside a {@see Customer}, so nothing has to guess which of the two an
 * address was standing in for.
 *
 * The split is not cosmetic. ConnexPay's `Card.Customer` holds four person fields AND six AVS
 * fields in one object; while they shared a type, sending the person meant sending the address
 * and there was no way to say only one of them was known. What is left here is exactly what
 * address verification reads.
 */
final readonly class BillingAddress
{
    public function __construct(
        #[Pii(ShreddingStubs::ADDRESS_LINE)] public string $line,
        public string $city,
        public Country $country,
        public string $postalCode,
        #[Pii(ShreddingStubs::ADDRESS_LINE)] public string $lineExtra = '',
        public ?State $state = null,
    ) {}

    /**
     * An address for a payment whose billing details we were never given.
     *
     * Every field is the {@see ShreddingStubs} sentinel for its type, which is the same marker a
     * GDPR-erased row carries — so "we never had this" and "we deleted this" read identically
     * downstream, which is correct: in both cases there is no data, and neither should be
     * mistaken for a real address. The country is `ZZ`, ISO 3166's own code for an unknown one,
     * rather than a guess that would feed AVS and reporting something false.
     *
     * Needed because only {@see \Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentImported}
     * accepts a null customer; the charge, authorize and requires-action events all demand one,
     * so an imported intent with null could never resolve.
     */
    public static function unknown(): self
    {
        return new self(
            line: ShreddingStubs::ADDRESS_LINE,
            city: ShreddingStubs::CITY,
            country: new Country(ShreddingStubs::COUNTRY),
            postalCode: ShreddingStubs::POSTAL_CODE,
        );
    }

    /**
     * Whether this is the "no data" marker rather than somewhere a person lives.
     *
     * Needed because the address stopped being nullable when it moved inside a {@see Customer}:
     * a mapper used to omit its billing block on null, and the marker is what a caller says now
     * in place of that null. Sending `ZZ` and a stubbed street to a provider as though they were
     * facts is the thing this lets a mapper avoid — at Stripe it is worse than useless, because
     * `customers.create` with an `address` block overwrites whatever the record held.
     *
     * The country alone decides it. `ZZ` is ISO 3166's user-assigned code for an unknown country
     * and nothing real carries it, so a partially-stubbed address — a street nobody gave in a
     * city and country somebody did — still reads as an address, which is correct.
     */
    public function isUnknown(): bool
    {
        return (string) $this->country === ShreddingStubs::COUNTRY;
    }

    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'line_extra' => $this->lineExtra,
            'city' => $this->city,
            'country' => (string) $this->country,
            'postal_code' => $this->postalCode,
            'state' => $this->state ? (string) $this->state : null,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            line: $data['line'],
            city: $data['city'],
            country: new Country($data['country']),
            postalCode: $data['postal_code'],
            lineExtra: $data['line_extra'] ?? '',
            state: ! empty($data['state']) ? new State($data['state']) : null,
        );
    }
}
