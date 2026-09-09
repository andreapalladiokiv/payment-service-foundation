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
 * A stored instrument, an id of ours for it, and — once somebody has claimed it — the customer it
 * belongs to.
 *
 * **Attached is a STATE of a payment method, not a second type.** `$customer` is null until the
 * card is claimed and non-null afterwards; {@see isAttached()} is that question asked by name.
 * There was an `AttachedPaymentMethod` pairing for a while, and one type with two states is the
 * better shape for a reason that showed up as a defect rather than as a preference: two types for
 * one credential meant two of everything downstream, and the first thing to get it wrong was the
 * gateway-reference key, which read the row's type off the wrapper and its id off the card inside.
 * One class has one `type()`, so a reference is keyed on the credential by construction — see
 * {@see \Techork\PaymentService\Laravel\Repository\EloquentGatewayInstrumentRepository}.
 *
 * **Creating one does not attach it.** A payment method is minted by tokenising a card, which
 * knows nothing about who will own it; claiming it is a separate operation, and until it happens
 * `$customer` is null. That is an ordinary, expected state — not a half-built object.
 *
 * **Payment operations refuse the unattached state.** A stored card is charged to somebody, the
 * somebody is not derivable from the card, and the old answer was the address the payment method
 * carried — it held the payer's name, email and phone, one uncorrectable copy per card, so a card
 * was charged to whoever it happened to be billed to. The gateways now decline with
 * {@see \Techork\PaymentService\Gateway\Exception\UnsupportedInstrument::needsAttachedCustomer()}.
 *
 * Worth being plain about what that costs: while the pairing was a type, "payable" was a
 * guarantee a signature could carry, and now it is a runtime check every payment mapper has to
 * make. The check is one `null` comparison in each `visitPaymentMethod()` and each one is tested,
 * which is the price of the state living where it belongs.
 *
 * The address inside the customer is an address and only that. `$billingAddress` used to be a
 * field here, and it carried the payer — those four fields are {@see CustomerIdentity}'s.
 */
final readonly class PaymentMethod implements PaymentInstrument
{
    private const string TYPE = 'payment_method';

    public function __construct(
        public PaymentMethodId $id,
        public PaymentInstrument $instrument,
        public ?Customer $customer = null,
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

    /**
     * Whether anybody has claimed this card.
     *
     * The one question the payment mappers ask, named rather than written out as
     * `$paymentMethod->customer !== null` at each of them — a bare null check reads as "might be
     * missing" where this reads as the state it is.
     */
    public function isAttached(): bool
    {
        return $this->customer !== null;
    }

    /**
     * Whether this card is claimed by that customer specifically.
     *
     * Through {@see CustomerId::equals()}, which checks the class as well as the value: a customer
     * id and a payment method id standing on the same UUID are not the same thing, and a
     * comparison that could not tell them apart is how a card gets attributed to the wrong record.
     * An unattached card belongs to nobody, so the answer is false rather than an error — asking
     * is legitimate, and "no" is the truth.
     */
    public function belongsTo(CustomerId $customerId): bool
    {
        return $this->customer !== null && $this->customer->id->equals($customerId);
    }

    /**
     * The instrument's own validity, and nothing about the customer.
     *
     * Every part of a {@see Customer} is required, so there is nothing about the payer that could
     * make a card invalid — and being unattached is not invalidity either. Whether a card may be
     * CHARGED is a separate question the gateways answer, because the reason is theirs.
     */
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
            'customer' => $this->customer?->toArray(),
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
            // Absent reads as unattached, which is what a row written before the customer existed
            // means: nobody had claimed the card, because there was nowhere to record it. A
            // `billing_address` such a row may also carry is ignored — it held the payer, and
            // reading it would put the uncorrectable copy back.
            isset($payload['customer']) ? Customer::fromArray($payload['customer']) : null,
        );
    }

    private static function findInstrumentPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value) && isset($value['type']) && ! in_array($key, ['type', 'id', 'billing_address', 'customer'], true)) {
                return $value;
            }
        }

        throw new InvalidArgumentException('No instrument payload found in PaymentMethod data.');
    }
}
