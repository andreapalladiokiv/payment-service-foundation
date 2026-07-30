<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use libphonenumber\NumberParseException;
use Techork\PaymentService\Common\Pii;
use Techork\PaymentService\Common\ShreddingStubs;

final readonly class BillingAddress
{
    public function __construct(
        #[Pii(ShreddingStubs::NAME)] public string $firstName,
        #[Pii(ShreddingStubs::NAME)] public string $lastName,
        #[Pii(ShreddingStubs::ADDRESS_LINE)] public string $line,
        public string $city,
        public Country $country,
        public string $postalCode,
        #[Pii(ShreddingStubs::ADDRESS_LINE)] public string $lineExtra = '',
        public ?State $state = null,
        #[Pii(new Email(ShreddingStubs::EMAIL))] public ?Email $email = null,
        #[Pii(new PhoneNumber(ShreddingStubs::PHONE))] public ?PhoneNumber $phone = null,
    ) {}

    /**
     * An address for a payment whose billing details we were never given.
     *
     * Every field is the {@see ShreddingStubs} sentinel for its type, which is
     * the same marker a GDPR-erased row carries — so "we never had this" and "we
     * deleted this" read identically downstream, which is correct: in both cases
     * there is no data, and neither should be mistaken for a real address. The
     * country is `ZZ`, ISO 3166's own code for an unknown one, rather than a
     * guess that would feed AVS and reporting something false.
     *
     * Needed because only {@see \Techork\PaymentService\Domain\PaymentIntent\Event\PaymentIntentImported}
     * accepts a null billing address; the charge, authorize and requires-action
     * events all demand one, so an imported intent with null could never resolve.
     */
    public static function unknown(): self
    {
        return new self(
            firstName: ShreddingStubs::NAME,
            lastName: ShreddingStubs::NAME,
            line: ShreddingStubs::ADDRESS_LINE,
            city: ShreddingStubs::CITY,
            country: new Country(ShreddingStubs::COUNTRY),
            postalCode: ShreddingStubs::POSTAL_CODE,
        );
    }

    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'line' => $this->line,
            'line_extra' => $this->lineExtra,
            'city' => $this->city,
            'country' => (string) $this->country,
            'postal_code' => $this->postalCode,
            'state' => $this->state ? (string) $this->state : null,
            'email' => $this->email ? (string) $this->email : null,
            'phone' => $this->phone ? (string) $this->phone : null,
        ];
    }

    /**
     * @throws NumberParseException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            line: $data['line'],
            city: $data['city'],
            country: new Country($data['country']),
            postalCode: $data['postal_code'],
            lineExtra: $data['line_extra'] ?? '',
            state: ! empty($data['state']) ? new State($data['state']) : null,
            email: ! empty($data['email']) ? new Email($data['email']) : null,
            phone: ! empty($data['phone']) ? new PhoneNumber($data['phone']) : null,
        );
    }
}
