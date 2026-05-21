<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Exception;

use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;

final class PaymentIntentChallengeNotPending extends \DomainException
{
    public static function withStatus(PaymentIntentStatus $status): self
    {
        return new self("PaymentIntent has no pending 3DS challenge in status [{$status->value}].");
    }
}
