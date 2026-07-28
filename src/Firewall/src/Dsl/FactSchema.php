<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Dsl;

/**
 * Describes the facts a chain's rules are allowed to talk about. This is what
 * keeps the DSL engine free of any particular domain vocabulary: the engine
 * knows the grammar (matchers, membership, ranges, AND, first-match-wins), and
 * a schema supplies the names and types that fill it.
 *
 * Implementations are the authority on two things and nothing else:
 *  - {@see roots()} — the sandbox. Only these top-level variables may appear in
 *    an authored rule; ExpressionLanguage rejects anything else at compile time,
 *    so this list IS the security boundary at authoring time.
 *  - {@see typeOf()} — the declared type of a dot-path, used to coerce literals.
 *    Unknown paths return {@see FieldType::Mixed} rather than throwing: the root
 *    is the boundary, the path beneath it is free, so a schema may legitimately
 *    know fewer paths than a rule references.
 */
interface FactSchema
{
    /**
     * The top-level fact variables an authored rule may reference.
     *
     * @return array<int, string>
     */
    public function roots(): array;

    /**
     * The declared type of a fact dot-path, or {@see FieldType::Mixed}.
     */
    public function typeOf(string $path): FieldType;
}
