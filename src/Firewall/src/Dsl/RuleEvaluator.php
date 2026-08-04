<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Dsl;

use Psr\Cache\CacheItemPoolInterface;
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
 *
 * PARSING IS THE EXPENSIVE PART, and it is cached rather than avoided. Every
 * evaluation hands ExpressionLanguage a compiled string, which it lexes and
 * parses into its own node tree; on a chain of any size that dominates the cost
 * of evaluating the tree by an order of magnitude. ExpressionLanguage keys that
 * tree by expression text and caches it in the pool given here, so supplying a
 * pool that OUTLIVES THE REQUEST is what turns per-request parsing into
 * per-deploy parsing. Passing none keeps Symfony's default in-memory pool, which
 * dies with the request and therefore never hits — correct, just not fast.
 *
 * Choose a LOCAL pool: filesystem, APCu, or anything else that answers without
 * leaving the box. ExpressionLanguage looks up one cache key per expression, so
 * a chain of N rules is N lookups; against a networked pool that is N round
 * trips per payment, which costs more than the parsing it saves.
 */
final class RuleEvaluator
{
    private ExpressionLanguage $expressionLanguage;

    /**
     * @param  CacheItemPoolInterface|null  $parseCache  local, request-outliving
     *                                                   pool for parsed expressions
     */
    public function __construct(
        private readonly RuleCompiler $compiler,
        private readonly FactSchema $schema,
        ?CacheItemPoolInterface $parseCache = null,
    ) {
        $this->expressionLanguage = new ExpressionLanguage($parseCache);

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
