<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use DateMalformedStringException;
use InvalidArgumentException;
use libphonenumber\NumberParseException;
use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;

/**
 * A stored instrument and an id of ours for it. Nothing else.
 *
 * **Deliberately not a customer, and no longer an address either.** It held a
 * {@see BillingAddress}, and that address held the payer's name, email and phone — so a payment
 * method was a person, an uncorrectable copy of one per card, which is exactly how every provider
 * mapper came to read the payer off whatever card was being charged. Both halves live on
 * {@see Customer} now, and a payment method paired with one is an {@see AttachedPaymentMethod}.
 *
 * **This is therefore not payable.** A gateway asked to charge a bare payment method refuses it:
 * a stored card exists to be charged again to somebody, and there is no honest way to name that
 * somebody from the instrument alone — the old answer was the address it carried, which is the
 * behaviour being removed. Payment operations take an `AttachedPaymentMethod`; this type is what
 * a vaulting operation produces and what an attachment is built from.
 *
 * **The stored payload shape changed with it**: `billing_address` is gone. Rows written before
 * that still carry the key and {@see fromPayload()} ignores it, because an address on a payment
 * method has nowhere left to go — reading it would silently reintroduce the copy.
 */
final readonly class PaymentMethod implements PaymentInstrument
{
    private const string TYPE = 'payment_method';

    public function __construct(
        public PaymentMethodId $id,
        public PaymentInstrument $instrument,
    ) {}

    #[Override]
    public static function type(): string
    {
        return self::TYPE;
    }

    #[Override]
    public function accept(PaymentInstrumentVisitor $visitor): mixed
    {
        return $visitor->visitPaymentMethod($this);
    }

    #[Override]
    public function isValid(): bool
    {
        return $this->instrument->isValid();
    }

    #[Override]
    public function toPayload(): array
    {
        $instrumentPayload = $this->instrument->toPayload();

        return [
            'id' => $this->id->toString(),
            'type' => self::TYPE,
            $instrumentPayload['type'] => $instrumentPayload,
        ];
    }

    /**
     * @throws NumberParseException
     * @throws DateMalformedStringException
     */
    #[Override]
    public static function fromPayload(array $payload): self
    {
        return new self(
            PaymentMethodId::fromString($payload['id']),
            PaymentInstrumentFactory::fromPayload(self::findInstrumentPayload($payload)),
        );
    }

    private static function findInstrumentPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            // `billing_address` is named among the keys that are not the instrument because rows
            // written before it was removed still hold one, and it is shaped enough like an
            // instrument payload to be mistaken for one.
            if (is_array($value) && isset($value['type']) && ! in_array($key, ['type', 'id', 'billing_address', 'customer'], true)) {
                return $value;
            }
        }

        throw new InvalidArgumentException('No instrument payload found in PaymentMethod data.');
    }
}
