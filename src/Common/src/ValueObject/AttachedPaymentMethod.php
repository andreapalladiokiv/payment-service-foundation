<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use DateMalformedStringException;
use libphonenumber\NumberParseException;
use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;

/**
 * A stored payment method together with the customer it belongs to — and the only form of a
 * stored instrument a gateway will take a payment on.
 *
 * **Attaching is an operation of its own.** Creating a {@see PaymentMethod} creates an instrument
 * and nothing else: no customer is minted for it and none is attached. That separation is the
 * whole reason this type exists. While the two were one thing, storing a card meant inventing a
 * person to store it for, and what got invented was whoever the card happened to be billed to —
 * the address on the payment method *was* the payer. A card with nobody attached is an ordinary,
 * expected state; it is simply not chargeable until somebody claims it.
 *
 * **Refusing a bare `PaymentMethod` at the gateway is the point.** Every payment mapper needs the
 * payer: Nuvei's billing block names them beside the address, ConnexPay's `Card.Customer` holds
 * both in one object, Stripe will not reuse a PaymentMethod outside the Customer it is attached
 * to. Given only an instrument, a mapper has to get the person from somewhere, and "somewhere"
 * was the address. A type that carries both makes the wrong answer unavailable rather than
 * unlikely — see the visitor's `visitPaymentMethod()` implementations, which now decline.
 *
 * It is here rather than in a customer's own package because both halves are `Common`'s: this
 * pairing lived in `Domain` beside a `Customer` aggregate, forced there because `CustomerId`
 * implemented EventSauce's `AggregateRootId` and `Common` cannot depend on eventsauce. The
 * aggregate is gone and the constraint went with it.
 *
 * {@see belongsTo()} compares through {@see CustomerId::equals()}, which is class-checked as well
 * as value-checked: a customer id and a payment method id standing on the same UUID are not the
 * same thing, and a comparison that could not tell them apart is how a card gets attributed to
 * the wrong record. This went through a string comparison for as long as the id was an interface
 * whose only contract was `toString()`; the interface is gone and the stronger check is back.
 */
final readonly class AttachedPaymentMethod implements PaymentInstrument
{
    private const string TYPE = 'attached_payment_method';

    public function __construct(
        public Customer $customer,
        public PaymentMethod $paymentMethod,
    ) {}

    #[Override]
    public static function type(): string
    {
        return self::TYPE;
    }

    #[Override]
    public function accept(PaymentInstrumentVisitor $visitor): mixed
    {
        return $visitor->visitAttachedPaymentMethod($this);
    }

    /**
     * The instrument's own validity and nothing about the customer.
     *
     * A customer cannot be invalid — every part of one is required — so there is nothing here to
     * add to what the card already says about itself.
     */
    #[Override]
    public function isValid(): bool
    {
        return $this->paymentMethod->isValid();
    }

    public function id(): string
    {
        return $this->paymentMethod->id->toString();
    }

    public function belongsTo(CustomerId $customerId): bool
    {
        return $this->customer->id->equals($customerId);
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'customer' => $this->customer->toArray(),
            'payment_method' => $this->paymentMethod->toPayload(),
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
            Customer::fromArray($payload['customer']),
            PaymentMethod::fromPayload($payload['payment_method']),
        );
    }
}
