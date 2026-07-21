<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port;

use Techork\PaymentService\Common\ValueObject\Risk\FraudVerdict;

/**
 * Result of a {@see RiskDecisionPort::decide()} call: the {@see RiskAction} the
 * aggregate must enforce, plus the evidence behind it.
 *
 * `fraudReference` is the reference (UUID) to persist and forward downstream as
 * `fraudPrevention.uuid`. `verdict` is the underlying provider recommendation
 * when a screening ran (null when the action came from a rule that skipped
 * screening entirely). `reason` is a human-readable explanation, e.g. the
 * matched rule ids, for audit.
 */
final readonly class RiskOutcome
{
    public function __construct(
        public RiskAction $action,
        public ?string $fraudReference = null,
        public ?FraudVerdict $verdict = null,
        public ?string $reason = null,
    ) {}

    public function requiresThreeDS(): bool
    {
        return $this->action === RiskAction::Require3ds;
    }

    public function skipsThreeDS(): bool
    {
        return $this->action === RiskAction::Skip3ds;
    }
}
