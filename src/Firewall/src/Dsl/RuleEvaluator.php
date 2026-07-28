<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Dsl;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Evaluates a compiled rule against a set of facts, inside the sandbox.
 *
 * The sandbox has three walls, and all three matter:
 *  1. ExpressionLanguage cannot call arbitrary PHP — only operators, literals,
 *     the supplied variables, and {@see ExpressionFunctions}.
 *  2. At evaluation time a rule can only reach the fact roots the caller passed;
 *     at authoring time {@see validate()} compiles against the schema's roots,
 *     so an unknown root is rejected when the rule is saved rather than silently
 *     failing later.
 *  3. Facts are flattened through JSON before evaluation. That both exposes
 *     nested arrays as objects so dot-paths resolve, and — the security part —
 *     strips value objects and closures, so an authored rule cannot call a
 *     method on a domain object and reach behaviour. Do not "optimise" the
 *     round-trip away.
 */
final class RuleEvaluator
{
    private ExpressionLanguage $expressionLanguage;

    public function __construct(
        private readonly RuleCompiler $compiler,
        private readonly FactSchema $schema,
    ) {
        $this->expressionLanguage = new ExpressionLanguage;

        foreach (ExpressionFunctions::all() as $function) {
            $this->expressionLanguage->addFunction($function);
        }
    }

    /**
     * @param  array<int|string, mixed>|null  $conditions
     * @param  array<string, mixed>  $facts  keyed by root; nested arrays are
     *                                       exposed as objects so dot-paths resolve
     */
    public function matches(?array $conditions, array $facts, ?string $expression = null): bool
    {
        return (bool) $this->expressionLanguage->evaluate(
            $this->compiler->compile($conditions, $expression),
            $this->toVariables($facts),
        );
    }

    /**
     * Compile-check a rule without evaluating it: throws on an unknown fact root
     * or operator (via the compiler) and on unparseable expression text or an
     * unknown function (via ExpressionLanguage). Use this at save time — it is
     * where authoring mistakes are meant to surface.
     *
     * @param  array<int|string, mixed>|null  $conditions
     */
    public function validate(?array $conditions, ?string $expression = null): void
    {
        $this->expressionLanguage->compile(
            $this->compiler->compile($conditions, $expression),
            $this->schema->roots(),
        );
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function toVariables(array $facts): array
    {
        return array_map(
            static fn (mixed $value): mixed => is_array($value)
                ? json_decode((string) json_encode($value), false)
                : $value,
            $facts,
        );
    }
}
