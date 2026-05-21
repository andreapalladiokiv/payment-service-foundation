<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use JsonSerializable;
use Override;
use RuntimeException;
use Stringable;
use Symfony\Component\Intl\Countries;
use Techork\PaymentService\Common\ShreddingStubs;

final readonly class Country implements JsonSerializable, Stringable
{
    private string $country;

    public function __construct(string $country)
    {
        if (strlen($country) === 3) {
            if (is_numeric($country)) {
                $this->country = Countries::getAlpha2FromNumeric($country);

                return;
            }

            $country = Countries::getAlpha2Code($country);
        }

        $this->country = $country;

        $this->validate();
    }

    private function validate(): void
    {
        // Shredding stub bypasses the ICU check by design — Symfony Intl
        // doesn't expose CLDR's "Unknown Region" code, so we whitelist it.
        if ($this->country === ShreddingStubs::COUNTRY) {
            return;
        }

        Countries::exists($this->country) || throw new RuntimeException('Country is not valid');
    }

    #[Override]
    public function __toString(): string
    {
        return $this->country;
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return (string) $this;
    }

    public function getAlpha3(): string
    {
        return Countries::getAlpha3Code($this->country);
    }
}
