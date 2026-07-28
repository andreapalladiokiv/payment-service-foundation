<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Rule;

use Techork\PaymentService\Domain\Firewall\FirewallVerdict;

/**
 * One rule in a chain: what to match, and what to answer when it matches.
 *
 * This is the engine's view of a rule — deliberately not a storage shape. How
 * rules are persisted and ordered is the application's business; a
 * {@see FirewallRuleSource} maps whatever it keeps onto this.
 *
 * `conditions` is the structured matcher set and `expression` the raw
 * ExpressionLanguage escape hatch; the two are AND-ed. Both being empty makes
 * the rule a catch-all, which is how a chain's closing default line is written.
 *
 * `id` is carried only so a decision can name which rule produced it, for
 * debugging and audit.
 */
final readonly class FirewallRule
{
    /**
     * @param  array<int|string, mixed>|null  $conditions
     */
    public function __construct(
        public FirewallVerdict $verdict,
        public ?array $conditions = null,
        public ?string $expression = null,
        public ?string $id = null,
    ) {}
}
