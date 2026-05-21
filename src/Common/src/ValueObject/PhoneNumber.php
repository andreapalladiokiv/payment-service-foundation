<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use JsonSerializable;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Override;
use Stringable;

final readonly class PhoneNumber implements JsonSerializable, Stringable
{
    private \libphonenumber\PhoneNumber $number;

    /**
     * @throws NumberParseException
     */
    public function __construct(string|PhoneNumber $number)
    {
        $this->number = PhoneNumberUtil::getInstance()->parse((string) $number);
    }

    #[Override]
    public function __toString(): string
    {
        return PhoneNumberUtil::getInstance()->format($this->number, PhoneNumberFormat::E164);
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
