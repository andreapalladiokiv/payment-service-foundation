<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\CreditCard;

use JsonSerializable;
use Override;
use Stringable;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;

final readonly class Cvc implements JsonSerializable, Stringable
{
    private string $data;

    public static function fromCvc(string $cvc, EncryptInterface $encrypter): self
    {
        $self = new self;
        $self->data = $encrypter->encrypt($cvc);

        return $self;
    }

    public function getCvc(DecryptInterface $decrypter): ?string
    {
        if (! isset($this->data)) {
            return null;
        }

        return $decrypter->decrypt($this->data);
    }

    /**
     * Drop the encrypted CVC on PHP serialization.
     *
     * PCI DSS 3.3.1: Sensitive Authentication Data must not be retained after
     * authorization. The saga library serialises subjects via native
     * `serialize()` between transitions; without this hook the encrypted CVC
     * would survive the round-trip into the `sagas.subject` column. After the
     * first gateway call (token registration / charge) inside the originating
     * HTTP request, CVC is no longer needed — subsequent saga transitions
     * reach the gateway via the registered Token reference. A cross-process
     * retry that reloads saga state therefore loads a CVC-less subject, which
     * is what PCI requires (no SAD-backed retries; the buyer re-submits).
     */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        // Intentional no-op: $this->data stays uninitialized so getCvc()
        // returns null after a serialize/unserialize round-trip.
    }

    #[Override]
    public function __toString(): string
    {
        return '';
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return '***';
    }
}
