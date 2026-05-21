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
