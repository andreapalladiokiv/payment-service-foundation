<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Dsl;

use InvalidArgumentException;

/**
 * Compiles one rule's conditions into a single Symfony ExpressionLanguage
 * string. This class is the only place the DSL becomes expression text.
 *
 * THE GRAMMAR (deliberately narrow, packet-filter style). A rule's conditions
 * are a flat set of typed matchers, one per fact, ALL AND-ed together; there is
 * no nesting and no combinator, because OR is expressed by writing another rule.
 * A matcher is exactly one of two shapes:
 *
 *  - membership: {"values": [...], "not": bool}
 *      compiles to a group of loose == comparisons (or != when negated). Loose
 *      rather than ExpressionLanguage's own `in`, which is a STRICT comparison
 *      and therefore fails across int-vs-float (fact 95.0 against value 95) and
 *      int-vs-string. A single value is just a one-element set.
 *  - range: {"min": n, "max": n}   (either bound may be omitted)
 *      compiles to `path >= min and path <= max`, the equivalent of a MikroTik
 *      port range.
 *
 * Conditions accept two equivalent serializations: a map keyed by fact path
 * (what an admin UI naturally writes, and which enforces one matcher per fact)
 * and a list of matchers each carrying its own `field` (convenient for
 * hand-authored configuration). Both compile identically.
 *
 * A matcher with neither values nor bounds is unconfigured and skipped. A rule
 * with nothing configured compiles to the literal `true`, which is a useful
 * catch-all line at the bottom of a chain.
 *
 * The optional raw expression is authored ExpressionLanguage, AND-ed onto the
 * matchers. It is the escape hatch for everything the structured grammar
 * deliberately omits: fact-to-fact comparison, OR, arithmetic. It is emitted
 * verbatim and only checked when the whole string is validated against the
 * schema's roots.
 */
final readonly class RuleCompiler
{
    public function __construct(private FactSchema $schema) {}

    /**
     * @param  array<int|string, mixed>|null  $conditions  matcher map or list
     * @param  string|null  $expression  raw ExpressionLanguage, AND-ed on
     */
    public function compile(?array $conditions, ?string $expression = null): string
    {
        $fragments = [];

        foreach ($conditions ?? [] as $key => $matcher) {
            if (! is_array($matcher)) {
                throw new InvalidArgumentException('Each firewall rule matcher must be an object.');
            }

            // Map form keys by fact path; list form carries it as "field".
            $fragment = $this->compileMatcher($matcher, is_string($key) ? $key : ($matcher['field'] ?? null));

            if ($fragment !== null) {
                $fragments[] = $fragment;
            }
        }

        $expression = is_string($expression) ? trim($expression) : '';

        if ($expression !== '') {
            $fragments[] = "($expression)";
        }

        return $fragments === [] ? 'true' : '('.implode(' and ', $fragments).')';
    }

    /**
     * One matcher, or null when it is unconfigured.
     *
     * @param  array<string, mixed>  $matcher
     */
    private function compileMatcher(array $matcher, mixed $field): ?string
    {
        $hasMin = $this->isFilled($matcher['min'] ?? null);
        $hasMax = $this->isFilled($matcher['max'] ?? null);

        if ($hasMin || $hasMax) {
            return $this->compileRange($this->field($field), $matcher, $hasMin, $hasMax);
        }

        $values = $this->values($matcher['values'] ?? null);

        if ($values === []) {
            return null;
        }

        return $this->compileMembership(
            $this->field($field),
            $values,
            negated: (bool) ($matcher['not'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $matcher
     */
    private function compileRange(string $field, array $matcher, bool $hasMin, bool $hasMax): string
    {
        $bounds = [];

        if ($hasMin) {
            $bounds[] = "$field >= ".$this->encode($this->number($matcher['min']));
        }

        if ($hasMax) {
            $bounds[] = "$field <= ".$this->encode($this->number($matcher['max']));
        }

        return '('.implode(' and ', $bounds).')';
    }

    /**
     * Membership as a group of loose comparisons: any-of when plain, none-of
     * when negated (so negation distributes as AND, not OR).
     *
     * @param  array<int, string>  $values
     */
    private function compileMembership(string $field, array $values, bool $negated): string
    {
        $type = $this->schema->typeOf($field);
        $comparison = $negated ? '!=' : '==';
        $glue = $negated ? ' and ' : ' or ';

        $parts = array_map(
            fn (string $value): string => "$field $comparison ".$this->encode($this->coerce($value, $type)),
            $values,
        );

        return '('.implode($glue, $parts).')';
    }

    /**
     * Only the path's ROOT is checked here — that is the sandbox boundary. The
     * dot-path beneath it is free, so a schema need not enumerate every fact.
     */
    private function field(mixed $field): string
    {
        if (! is_string($field) || $field === '') {
            throw new InvalidArgumentException('Firewall rule matcher is missing a field.');
        }

        $root = explode('.', $field, 2)[0];

        if (! in_array($root, $this->schema->roots(), true)) {
            throw new InvalidArgumentException("Unknown firewall rule fact root: {$root}");
        }

        return $field;
    }

    /**
     * Coerce an authored literal to the fact's declared type so the comparison
     * is made between like kinds.
     */
    private function coerce(string $value, FieldType $type): string|int|float|bool
    {
        return match ($type) {
            FieldType::Boolean => in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true),
            FieldType::Number => is_numeric($value) ? $this->number($value) : $value,
            default => $value,
        };
    }

    private function isFilled(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function number(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Firewall rule range bounds must be numeric.');
        }

        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    /**
     * Normalise a matcher value into a list: an array passes through, a
     * comma-separated string is split (the shape a tag input produces), and
     * blank entries are dropped.
     *
     * @return array<int, string>
     */
    private function values(mixed $value): array
    {
        $items = match (true) {
            is_array($value) => $value,
            is_string($value) => explode(',', $value),
            $value === null => [],
            default => [$value],
        };

        $items = array_map(static fn (mixed $item): string => trim((string) $item), $items);

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * Encode a PHP value as an ExpressionLanguage literal. json_encode already
     * yields EL-compatible syntax for the only shapes a rule uses: strings,
     * numbers and booleans.
     */
    private function encode(string|int|float|bool $value): string
    {
        try {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Firewall rule value is not encodable.', previous: $e);
        }

        return $encoded;
    }
}
