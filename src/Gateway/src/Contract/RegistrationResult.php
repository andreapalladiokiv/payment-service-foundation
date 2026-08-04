<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;

/**
 * Result of a tokenize / createPaymentMethod gateway operation. Extends
 * {@see GatewayResult} with the extra signals the gateway may return:
 *
 *  - {@see $customerReference} — issuer-side / processor-side customer id
 *    that future ops can attach to.
 *  - {@see $storedCredentialReference} — the transaction that ESTABLISHED the
 *    credential, when the registration was itself one. Not the same value as
 *    the inherited reference, which names the stored instrument; see
 *    {@see StoredCredentialReferenceProvider} for why they never coincide.
 *  - card-verification fields ({@see $addressLineCheck},
 *    {@see $postalCodeCheck}, {@see $cvcCheck}) — `null` means the operation
 *    carried no signal for that field; a non-null {@see CheckResult}
 *    (including {@see CheckResult::Unchecked}) is a real signal.
 *
 * Inherits {@see GatewayResult::succeeded} / {@see GatewayResult::failed}
 * static factories (return type {@see static}, so they yield
 * {@see RegistrationResult} when called on this subclass). Customer
 * reference and checks attach via the {@see withCustomerReference} /
 * {@see withChecks} withers — this avoids LSP-incompatible factory
 * signatures while keeping construction fluent.
 */
final readonly class RegistrationResult extends GatewayResult
{
    public function __construct(
        bool $success,
        ?string $reference,
        ?string $message,
        public ?string $customerReference = null,
        public ?CheckResult $addressLineCheck = null,
        public ?CheckResult $postalCodeCheck = null,
        public ?CheckResult $cvcCheck = null,
        public ?string $storedCredentialReference = null,
    ) {
        parent::__construct($success, $reference, $message);
    }

    public function withCustomerReference(?string $customerReference): self
    {
        return new self(
            $this->success,
            $this->reference,
            $this->message,
            $customerReference,
            $this->addressLineCheck,
            $this->postalCodeCheck,
            $this->cvcCheck,
            $this->storedCredentialReference,
        );
    }

    public function withStoredCredentialReference(?string $storedCredentialReference): self
    {
        return new self(
            $this->success,
            $this->reference,
            $this->message,
            $this->customerReference,
            $this->addressLineCheck,
            $this->postalCodeCheck,
            $this->cvcCheck,
            $storedCredentialReference,
        );
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
            $this->customerReference,
            $addressLineCheck,
            $postalCodeCheck,
            $cvcCheck,
            $this->storedCredentialReference,
        );
    }

    public function hasChecks(): bool
    {
        return $this->addressLineCheck !== null
            || $this->postalCodeCheck !== null
            || $this->cvcCheck !== null;
    }
}
