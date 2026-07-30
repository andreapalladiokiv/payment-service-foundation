<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use JsonSerializable;
use Override;
use RuntimeException;
use Stringable;

/**
 * The text the cardholder sees on their bank statement for this payment.
 *
 * Two invariants, both of them properties of the card networks rather than of
 * any one gateway:
 *
 *  - **Length.** Capped at 25, which is the widest descriptor any gateway we
 *    integrate accepts (ConnexPay); most cap at 22. The narrower per-gateway
 *    limit is not enforced here — it varies by acquirer, so it belongs to the
 *    request validation that already knows which gateway the payment is routed
 *    to. What this rejects is a descriptor no acquirer would take.
 *  - **Character set.** Printable ASCII only, and never `< > \ " '`. The
 *    networks reject these outright, and they are the characters that turn a
 *    descriptor into an injection vector on the way to a statement renderer.
 *
 * Empty is deliberately valid. A payment may carry no descriptor — 8,092 of the
 * intents on record do — and an acquirer then falls back to the merchant's
 * configured default. Rejecting empty here would make those unimportable.
 *
 * Stored as one private string whose name matches the constructor parameter, so
 * Symfony's PropertyNormalizer round-trips it without a dedicated normalizer —
 * unlike {@see PhoneNumber}, whose inner object shape needs
 * {@see \Techork\PaymentService\Laravel\Serializer\PhoneNumberNormalizer}.
 */
final readonly class MerchantDescriptor implements JsonSerializable, Stringable
{
    public const int MAX_LENGTH = 25;

    private const string FORBIDDEN = '<>\\"\'';

    public function __construct(private string $descriptor)
    {
        $this->validate();
    }

    public static function none(): self
    {
        return new self('');
    }

    public function isEmpty(): bool
    {
        return $this->descriptor === '';
    }

    private function validate(): void
    {
        mb_strlen($this->descriptor) <= self::MAX_LENGTH
            || throw new RuntimeException('Merchant descriptor exceeds '.self::MAX_LENGTH.' characters');

        // Printable ASCII, space through tilde. Excludes control characters and
        // anything multi-byte, both of which the networks reject.
        preg_match('/^[\x20-\x7E]*$/', $this->descriptor) === 1
            || throw new RuntimeException('Merchant descriptor must be printable ASCII');

        strpbrk($this->descriptor, self::FORBIDDEN) === false
            || throw new RuntimeException('Merchant descriptor must not contain any of '.self::FORBIDDEN);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->descriptor;
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
