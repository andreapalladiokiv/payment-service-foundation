<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\Pii;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\CreditCard\Address;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;

final readonly class CreditCard implements PaymentInstrument
{
    private const string TYPE = 'card';

    public function __construct(
        public Number $number,
        public Expiration $expiration,
        #[Pii(new Holder(ShreddingStubs::NAME))] public Holder $holder,
        public Cvc $cvc,
        public ?Address $address = null,
        public CheckResult $addressLineCheck = CheckResult::Unchecked,
        public CheckResult $postalCodeCheck = CheckResult::Unchecked,
        public CheckResult $cvcCheck = CheckResult::Unchecked,
    ) {}

    #[Override]
    public static function type(): string
    {
        return self::TYPE;
    }

    /**
     * @throws DateMalformedStringException
     */
    public static function fromArray(array $data): self
    {
        $expiration = DateTimeImmutable::createFromFormat('my', $data['expiration'])
            ?: throw new InvalidArgumentException("Stored card expiration '{$data['expiration']}' is not a readable date.");

        return new self(
            new Number($data['first6'], $data['last4'], CardBrand::from($data['brand'])),
            new Expiration($expiration),
            new Holder($data['holder']),
            new Cvc,
            isset($data['address']) ? new Address(...$data['address']) : null,
            isset($data['address_line_check']) ? CheckResult::from($data['address_line_check']) : CheckResult::Unchecked,
            isset($data['postal_code_check']) ? CheckResult::from($data['postal_code_check']) : CheckResult::Unchecked,
            isset($data['cvc_check']) ? CheckResult::from($data['cvc_check']) : CheckResult::Unchecked,
        );
    }

    #[Override]
    public function accept(PaymentInstrumentVisitor $visitor): mixed
    {
        return $visitor->visitCreditCard($this);
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'first6' => $this->number->first6,
            'last4' => $this->number->last4,
            'brand' => $this->number->brand->value,
            'expiration' => $this->expiration->format('my'),
            'holder' => (string) $this->holder,
            'address_line_check' => $this->addressLineCheck->value,
            'postal_code_check' => $this->postalCodeCheck->value,
            'cvc_check' => $this->cvcCheck->value,
        ];
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Override]
    public static function fromPayload(array $payload): self
    {
        return self::fromArray($payload);
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Override]
    public function isValid(): bool
    {
        return ! $this->expired();
    }

    /**
     * @throws DateMalformedStringException
     */
    public function expired(): bool
    {
        return $this->expiration->expired();
    }
}
