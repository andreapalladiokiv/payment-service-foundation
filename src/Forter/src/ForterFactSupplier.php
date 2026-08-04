<?php

declare(strict_types=1);

namespace Techork\PaymentService\Forter;

use Techork\PaymentService\Common\Contract\FactSupplier;

/**
 * Exposes a Forter screening verdict as firewall facts under the `screening`
 * root, so operator-authored rules can weigh the provider's opinion alongside
 * everything else.
 *
 * This is the point of the supplier: previously a decline or an inconclusive
 * screening was acted on by a branch hard-coded ahead of the rules, which meant
 * operators could neither see it nor tune it. As facts it becomes ordinary rule
 * input — "decline plus a prepaid card" can be treated differently from
 * "decline plus a trusted BIN", and the fail-open / fail-closed choice for an
 * inconclusive screening becomes a rule instead of a config flag.
 *
 * Screening is a network call, so it runs once, when {@see facts()} is first
 * asked. A failure yields empty signals rather than an exception: the chain then
 * evaluates without them and rules that reference them do not match, which is
 * the same fail-soft contract {@see FraudScreeningProvider} already prescribes.
 * The verdict itself is kept on {@see verdict()} for callers that must persist
 * the provider's reason code or forward its reference downstream.
 */
final class ForterFactSupplier implements FactSupplier
{
    private bool $screened = false;

    private ?FraudVerdict $verdict = null;

    public function __construct(
        private readonly FraudScreeningProvider $screening,
        private readonly FraudScreeningRequest $request,
    ) {}

    public function facts(): array
    {
        $verdict = $this->screen();

        return [
            'screening' => [
                'decision' => $verdict?->decision->value,
                'reason_code' => $verdict?->reasonCode,
                'reference' => $verdict?->reference,
                'is_approved' => $verdict?->isApproved() ?? false,
                'is_declined' => $verdict?->isDeclined() ?? false,
                'is_inconclusive' => $verdict?->isInconclusive() ?? false,
            ],
        ];
    }

    /**
     * The verdict this supplier obtained, performing the screening call if it
     * has not run yet (at most once per instance); null when the call could not
     * complete.
     */
    public function verdict(): ?FraudVerdict
    {
        return $this->screen();
    }

    private function screen(): ?FraudVerdict
    {
        if ($this->screened) {
            return $this->verdict;
        }

        $this->screened = true;

        try {
            $this->verdict = $this->screening->screen($this->request);
        } catch (\Throwable) {
            // Provider unavailability is a missing signal, not a failed
            // assessment — the contract says a verdict, never an exception.
            $this->verdict = null;
        }

        return $this->verdict;
    }
}
