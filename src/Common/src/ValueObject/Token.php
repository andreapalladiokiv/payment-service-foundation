<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use DateMalformedStringException;
use InvalidArgumentException;
use libphonenumber\NumberParseException;
use Override;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;

final readonly class Token implements PaymentInstrument
{
    private const string TYPE = 'token';

    public function __construct(
        public TokenId $id,
        public PaymentInstrument $instrument,
        public ExpiresAt $expiresAt,
    ) {}

    #[Override]
    public static function type(): string
    {
        return self::TYPE;
    }

    #[Override]
    public function accept(PaymentInstrumentVisitor $visitor): mixed
    {
        return $visitor->visitToken($this);
    }

    #[Override]
    public function isValid(): bool
    {
        return ! $this->expiresAt->isExpired() && $this->instrument->isValid();
    }

    #[Override]
    public function toPayload(): array
    {
        $instrumentPayload = $this->instrument->toPayload();

        return [
            'type' => self::TYPE,
            'id' => $this->id->toString(),
            $instrumentPayload['type'] => $instrumentPayload,
            'expires_at' => $this->expiresAt->toPayload(),
        ];
    }

    /**
     * @throws NumberParseException
     * @throws DateMalformedStringException
     */
    #[Override]
    public static function fromPayload(array $payload): self
    {
        $instrumentPayload = self::findInstrumentPayload($payload);

        return new self(
            TokenId::fromString($payload['id']),
            PaymentInstrumentFactory::fromPayload($instrumentPayload),
            ExpiresAt::fromPayload($payload['expires_at']),
        );
    }

    private static function findInstrumentPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value) && isset($value['type']) && ! in_array($key, ['type', 'id', 'expires_at'], true)) {
                return $value;
            }
        }

        throw new InvalidArgumentException('No instrument payload found in Token data.');
    }
}
