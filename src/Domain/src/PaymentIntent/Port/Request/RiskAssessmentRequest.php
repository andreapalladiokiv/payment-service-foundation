<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\Risk\ConnectionContext;
use Techork\PaymentService\Domain\PaymentIntent\Port\RiskPhase;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * Domain-level input to {@see \Techork\PaymentService\Domain\PaymentIntent\Port\RiskDecisionPort::decide()}.
 * Carries the real {@see Money} amount (at authorization) or a zero amount (at
 * registration), the PCI-safe card summary, billing, and connection signals.
 *
 * `fraudReference` threads the reference produced by an earlier phase into a
 * later one (e.g. the registration-phase UUID reused at authorization), so the
 * two screenings are linked. `paymentIntentId` is null during registration,
 * when no intent exists yet.
 *
 * `gatewayId` scopes the decision to the gateway the payment will run through
 * so the implementation can apply per-gateway rules and fail policy. It is
 * null at registration (no gateway is chosen yet) and set at authorization.
 */
final readonly class RiskAssessmentRequest
{
    public function __construct(
        public Money $amount,
        public CardSummary $card,
        public BillingAddress $billing,
        public ConnectionContext $connection,
        public RiskPhase $phase,
        public ?PaymentIntentId $paymentIntentId = null,
        public ?string $fraudReference = null,
        public ?string $gatewayId = null,
    ) {}
}
