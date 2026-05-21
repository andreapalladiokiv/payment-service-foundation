<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\CreditCard;

use JsonSerializable;
use Override;
use Stringable;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\CardBrand;

final readonly class Number implements JsonSerializable, Stringable
{
    private ?string $number;

    public function __construct(
        public string $first6,
        public string $last4,
        public CardBrand $brand,
    ) {}

    public static function fromNumber(string $number, EncryptInterface $encrypter): self
    {
        $first6 = substr($number, 0, 6);
        $last4 = substr($number, -4);

        $self = new self($first6, $last4, CardBrand::fromNumber($number));
        $self->number = $encrypter->encrypt($number);

        return $self;
    }

    public function getNumber(DecryptInterface $decrypt): ?string
    {
        if (! isset($this->number)) {
            return null;
        }

        return $decrypt->decrypt($this->number);
    }

    public function __debugInfo(): array
    {
        return [
            'first6' => $this->first6,
            'last4' => $this->last4,
            'brand' => $this->brand->value,
        ];
    }

    #[Override]
    public function __toString(): string
    {
        return $this->first6.$this->last4;
    }

    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'brand' => $this->brand->value,
            'first6' => $this->first6,
            'last4' => $this->last4,
        ];
    }
}
