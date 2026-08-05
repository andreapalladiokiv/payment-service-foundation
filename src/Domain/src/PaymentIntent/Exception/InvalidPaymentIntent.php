<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use DomainException;
use EventSauce\EventSourcing\AggregateRootId;

final class InvalidPaymentIntent extends DomainException
{
    public static function nonPositiveAmount(): self
    {
        return new self('Payment intent amount must be positive.');
    }

    public static function alreadyExists(AggregateRootId $id): self
    {
        return new self(sprintf('Payment intent %s already exists and cannot be imported over.', $id->toString()));
    }

    public static function unusablePaymentSource(): self
    {
        return new self('Payment source is not usable (expired or consumed).');
    }

    /**
     * A hosted payment happens entirely on the gateway's own page: the buyer
     * enters their card there and the gateway decides when the money moves. We
     * hold no instrument to authorize now and capture later, so any capture
     * method other than `Immediate` describes a flow we cannot perform — and
     * every gateway in the fleet implements hosted on the charge path only.
     */
    public static function hostedPaymentRequiresImmediateCapture(string $captureMethod): self
    {
        return new self(sprintf(
            'A hosted payment cannot use the "%s" capture method — the payment happens on the gateway\'s page, so only immediate capture is possible.',
            $captureMethod,
        ));
    }


    public static function challengeResultCarriesNoEvidence(string $reason): self
    {
        return new self("Cannot confirm a challenge on an incoherent result: {$reason}.");
    }
}
