<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use DateMalformedStringException;
use InvalidArgumentException;
use libphonenumber\NumberParseException;
use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;

final readonly class PaymentMethod implements PaymentInstrument
{
    private const string TYPE = 'payment_method';

    public function __construct(
        public PaymentMethodId $id,
        public PaymentInstrument $instrument,
        public BillingAddress $billingAddress,
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
            'billing_address' => $this->billingAddress->toArray(),
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
            BillingAddress::fromArray($payload['billing_address']),
        );
    }

    private static function findInstrumentPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value) && isset($value['type']) && ! in_array($key, ['type', 'id', 'billing_address'], true)) {
                return $value;
            }
        }

        throw new InvalidArgumentException('No instrument payload found in PaymentMethod data.');
    }
}
