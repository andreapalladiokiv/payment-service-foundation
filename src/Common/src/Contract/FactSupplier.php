<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

/**
 * Supplies a slice of the fact tree the rules will inspect.
 *
 * Note there are no arguments: a supplier is constructed with whatever it needs
 * for the assessment at hand and then asked for its facts. That is what keeps
 * this package free of any domain or vendor vocabulary — a screening adapter's
 * supplier holds a typed screening request, an intelligence adapter's supplier
 * holds a BIN and an IP, and this package knows about neither.
 *
 * This is the extensibility axis: a new risk signal arrives as a new supplier
 * contributing new FACTS, which rules then reach through the same DSL. No new
 * mechanism is bolted beside the rules.
 *
 * Implementations MUST NOT throw — a supplier that cannot answer contributes
 * nothing (see {@see \Techork\PaymentService\Firewall\Fact\FactCollector}, which isolates failures). Emit plain
 * scalars and small arrays only: anything that cannot survive a JSON round-trip
 * is dropped when facts are flattened for evaluation, and NAN or INF would
 * nullify the tree it sits in.
 */
interface FactSupplier
{
    /**
     * Facts to merge, in the nested root-keyed shape the chain's schema declares
     * (e.g. `['payment_method' => ['source' => ['issuer_country' => 'GB']]]`).
     *
     * @return array<string, mixed>
     */
    public function facts(): array;
}
