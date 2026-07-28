# Firewall — rule-driven risk policy

`techork/payment-service-firewall` is the optional rule engine behind
`Techork\PaymentService\Domain\Firewall\FirewallPort`. It is the adapter between
the domain and its risk signals: rules are authored in the DSL it defines, and
risk providers contribute what rules match on through its `FactSupplier` seam.

Install it and a chain becomes evaluable. Leave it out and the domain falls back
to `NullFirewall`, which denies — fail-closed, like `iptables -P INPUT DROP`.

## The two sides

A firewall has two interfaces, and they point in opposite directions:

| Side | What it is | Where it lives |
| --- | --- | --- |
| **Input** — authoring | the rule DSL: what a rule may match on and how | this package (`src/Dsl/`), authored from config or an admin UI |
| **Output** — integration | one typed port per aggregate, e.g. `PaymentIntentFirewallPort::evaluate(PaymentIntentFirewallRequest): FirewallDecision` | declared in `payment-service-domain` |

Each aggregate's port is typed to the data that aggregate holds, so a caller
hands over a request and never a fact bag: naming the chain, assembling facts and
obtaining risk signals are all the implementation's job. The ports share only the
`FirewallDecision` vocabulary.

Inside, that work reduces to one chain walk against one fact tree, which is what
`Chain\ChainEvaluator` does — and why it deals in a chain *name* plus an
`array $facts`, with rule loading behind `Rule\FirewallRuleSource`. Nothing in
this package learns a vendor's vocabulary; facts arrive through
`Fact\FactSupplier`.

## The rule grammar

Packet-filter shaped. Rules are evaluated in chain order, **first match wins**,
and OR is expressed by writing another rule. One rule is a flat set of typed
matchers — all AND-ed, no nesting, no combinator — plus an optional raw
ExpressionLanguage escape hatch:

| Matcher | Shape | Compiles to |
| --- | --- | --- |
| membership | `{"values": [...], "not": false}` | `path == v1 or path == v2` (`!=` … `and` … when negated) |
| range | `{"min": n, "max": n}` | `path >= min and path <= max` (either bound optional) |

Conditions are either a map keyed by fact path (what an admin UI writes; one
matcher per fact) or a list of matchers each carrying its own `field`
(convenient in config). Both compile identically. A matcher with neither values
nor bounds is skipped; a rule with nothing configured compiles to `true`, which
is how a chain's closing default line is written.

Membership compiles to **loose** comparisons rather than ExpressionLanguage's
`in`, which is strict and would fail across int-vs-float (`95.0` against `95`)
and int-vs-string. Literals are coerced to the fact's declared `FieldType`
first — without that, `is_prepaid == "false"` compares against a non-empty
string and the rule silently inverts.

## The sandbox

Three walls, all load-bearing:

1. ExpressionLanguage cannot call arbitrary PHP — only operators, literals, the
   supplied fact variables, and the `ExpressionFunctions` whitelist
   (`includes`, `is_empty`, `is_not_empty`).
2. `RuleEvaluator::validate()` compiles a rule against `FactSchema::roots()`, so
   an unknown fact root is rejected when the rule is **saved**, not silently at
   evaluation time. Only the root is checked; the dot-path beneath it is free.
3. Facts are flattened through JSON before evaluation. That exposes nested arrays
   as objects so dot-paths resolve, and — the security part — strips value
   objects and closures, so a rule cannot call a method on a domain object and
   reach behaviour. There is a test pinning this; do not optimise the round-trip
   away.

## Fail-safety

A single malformed rule must never break a payment, so an unevaluable rule is
skipped. Skipping *silently* is how a chain quietly stops protecting anything,
so every skip is logged **and** recorded on the decision as
`FirewallDecision::$degraded` — including on a match, because the dangerous
shape is a reject rule that threw sitting above an accept rule that matched.
Callers must not treat a degraded result as a clean evaluation.

Falling off the end of a chain returns `FirewallDecision::noMatch()`, never a
fabricated verdict: the default policy belongs to the caller, which may vary it
per tenant or per phase.

## Contents

| Class | Role |
| --- | --- |
| `Chain\ChainEvaluator` | walks a chain against a fact tree, first match wins, tracks degradation |
| `Rule\FirewallRule` | one rule: verdict + conditions + optional raw expression + id |
| `Rule\FirewallRuleSource` | supplies a chain's rules in order — the seam that keeps storage out of the engine |
| `Fact\FactSupplier` | contributes a slice of the fact tree; no arguments, so this package learns no vendor vocabulary |
| `Fact\FactCollector` | merges suppliers and isolates their failures — a supplier that throws contributes nothing |
| `Dsl\RuleCompiler` | the only place the DSL becomes expression text |
| `Dsl\RuleEvaluator` | sandboxed evaluation and save-time validation |
| `Dsl\FactSchema` | the fact vocabulary: allowed roots plus declared types |
| `Dsl\FieldType` | declared type of a fact, used to coerce authored literals |
| `Dsl\ExpressionFunctions` | the function whitelist — widening it is a security decision |
