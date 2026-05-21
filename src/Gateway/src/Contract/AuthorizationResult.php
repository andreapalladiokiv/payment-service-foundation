<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;

/**
 * Result of an authorize / charge gateway operation. Extends
 * {@see GatewayResult} with two extra signals the gateway may return:
 *
 *  - {@see $challenge} — non-null when the operation transitioned to
 *    `requires_action` (3DS step-up, hosted redirect). The reference still
 *    points to the gateway transaction id so a later confirm/webhook can
 *    resolve it.
 *  - card-verification fields ({@see $addressLineCheck},
 *    {@see $postalCodeCheck}, {@see $cvcCheck}) — `null` means the operation
 *    carried no signal for that field; a non-null {@see CheckResult}
 *    (including {@see CheckResult::Unchecked}) is a real signal.
 */
final readonly class AuthorizationResult extends GatewayResult
{
    public function __construct(
        bool $success,
        ?string $reference,
        ?string $message,
        public ?Challenge $challenge = null,
        public ?CheckResult $addressLineCheck = null,
        public ?CheckResult $postalCodeCheck = null,
        public ?CheckResult $cvcCheck = null,
    ) {
        parent::__construct($success, $reference, $message);
    }

    public static function requiresAction(string $reference, Challenge $challenge): self
    {
        return new self(false, $reference, null, $challenge);
    }

    public function withChecks(
        ?CheckResult $addressLineCheck,
        ?CheckResult $postalCodeCheck,
        ?CheckResult $cvcCheck,
    ): self {
        return new self(
            $this->success,
            $this->reference,
            $this->message,
            $this->challenge,
            $addressLineCheck,
            $postalCodeCheck,
            $cvcCheck,
        );
    }

    public function isRequiresAction(): bool
    {
        return $this->challenge !== null;
    }

    public function hasChecks(): bool
    {
        return $this->addressLineCheck !== null
            || $this->postalCodeCheck !== null
            || $this->cvcCheck !== null;
    }
}
