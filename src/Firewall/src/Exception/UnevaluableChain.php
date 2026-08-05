<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Exception;

use RuntimeException;
use Throwable;

/**
 * A rule in the chain could not be evaluated, so the chain has no answer.
 *
 * This used to be survivable: the rule was skipped, the walk carried on, and the decision
 * carried a `degraded` flag saying so. The flag was the problem. It left every caller to decide
 * what a partly-evaluated chain was worth, and the one caller there is treated it the same as
 * everything else that did not permit — so a `Deny` rule with a typo in it silently stopped
 * protecting anything while the payment went through, which is exactly the shape a fail-open
 * hole takes here.
 *
 * A firewall that cannot evaluate its rules is not a degraded firewall, it is an absent one, so
 * it says so instead of answering. The cost is real and worth stating: one malformed rule stops
 * payments on that chain rather than quietly weakening them. That is the trade this package
 * chooses — a broken rule is an operator's emergency, not a silent discount on protection.
 */
final class UnevaluableChain extends RuntimeException
{
    public static function rule(string $chain, ?string $ruleId, Throwable $cause): self
    {
        return new self(
            sprintf(
                'Firewall chain "%s" cannot be evaluated: rule %s failed (%s).',
                $chain,
                $ruleId ?? '(unidentified)',
                $cause->getMessage(),
            ),
            previous: $cause,
        );
    }
}
