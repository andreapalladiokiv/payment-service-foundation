<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

interface PaymentInstrument
{
    public static function type(): string;

    public function isValid(): bool;

    /**
     * @template T
     *
     * @param PaymentInstrumentVisitor<T> $visitor
     * @return T
     */
    public function accept(PaymentInstrumentVisitor $visitor): mixed;

    public function toPayload(): array;

    public static function fromPayload(array $payload): PaymentInstrument;
}
