# Firewall — rule-driven risk policy

`techork/payment-service-firewall` is the optional rule engine behind
`Techork\PaymentService\Domain\PaymentIntent\Port\PaymentIntentFirewallPort`
(one typed port per aggregate). It is the adapter between
the domain and its risk signals: rules are authored in the DSL it defines, and
risk providers contribute what rules match on through its `FactSupplier` seam.

Install it and a chain becomes evaluable. Leave it out and the domain falls back
to `NullPaymentIntentFirewall`, which allows: with no firewall installed nothing
is inspected, which is what a payment service did before a firewall existed. It
denied once, reasoning from `iptables -P INPUT DROP`, and that was the wrong
analogy — a packet filter's default policy is a configured decision, this is the
absence of one, and it turned "the optional package is not installed" into a
step-up on every payment.

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
`Common\Contract\FactSupplier`.

## The rule grammar

Packet-filter shaped. Rules are evaluated in the order the strategy walks them —
in chain order and first match wins for the one that ships — and OR is expressed
by writing another rule. One rule is a flat set of typed
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

## Strategies: a chain is its rules *and* its posture

`ChainStrategy` owns the traversal, and `FirewallRuleSource` supplies one per
chain alongside the rules — the same rule list means opposite things under a
whitelist and under a blacklist, so a chain authored in an admin panel needs
somewhere to say which it is, and that somewhere is beside the rules rather than
in the engine's configuration.

`FirstMatchWins` is the only walk that ships: rules in order, the first match
answers, everything below it goes unread. Whitelist and blacklist are its two
defaults (`FirstMatchWins::whitelist()`, `::blacklist()`) rather than strategies
of their own, since the traversal is identical and only the answer for a subject
no rule mentioned differs. `::withDefault()` takes any of the three for a chain
that, say, steps up whatever its rules do not describe. A different traversal —
visiting every rule so the first `Deny` overrides an earlier `Allow` — is
written by implementing the interface, without touching the engine.

## The three actions

`Allow`, `Deny` and `Challenge`, all of them things a caller can carry out. There is deliberately
no "nothing matched": silence is not an action, and what the absence of a match means is the
chain's business, which its strategy answers.

`Challenge` is the middle answer, and the reason a firewall need not choose between waving a
suspicious payment through and refusing a legitimate one. **The engine does not carry it out.** It
briefly did, through a `ChallengeInitiator` it was handed — and could not: the facts it works from
hold a BIN and a last four and deliberately never a card number, while authenticating a cardholder
needs the pan, the expiry and the holder. Widening the fact vocabulary to supply them would have
turned the language an operator writes rules in into an argument list for one protocol.

So the decision travels, and the aggregate that holds the instrument acts on it, through
`Domain\PaymentIntent\Port\ChallengePort`. A `FirewallDecision` has nowhere to put an artefact,
which is what keeps the two jobs apart.

## Inspecting everything

Two kinds of payment used to skip the chain, and both were safe only while the firewall's one
power was to demand a step-up:

- a **merchant-initiated** payment, on the reasoning that nobody is present to answer one. True,
  and beside the point once a rule can refuse a payment outright — skipping meant every deny rule
  went unasked on exactly the traffic nobody is watching. It is inspected now, and a `Challenge`
  verdict on it is refused by the port rather than attempted.
- a payment arriving with a **finished 3DS result**, on the reasoning that the liability shift is
  already claimed. Also true, and also beside the point: that result comes from the caller, and
  the coherence check on it establishes that its fields agree with each other, not that an issuer
  ever saw the cardholder. Attaching a well-formed one walked past the whole chain.

`payment_intent.initiation` and `payment_intent.is_cardholder_initiated` exist because of the
first: a chain that must not step up unattended traffic now says so in a rule instead of relying
on never being asked.

## When a chain has no answer

`UnevaluableChain` — a rule that will not compile. This used to be survivable:
the rule was skipped and the decision carried a `degraded` flag. The flag was
the defect. It left every caller to decide what a partly-evaluated chain was
worth and the one caller there is treated it like everything else that did not
permit, so a `Deny` rule with a typo above an `Allow` that matched stopped
protecting anything while the payment went through.

The cost is real and worth stating: one malformed rule stops payments on that
chain instead of quietly weakening them. That is the trade this package chooses
— an operator's emergency, not a silent discount on protection.

A step-up that nothing can carry out is the same kind of problem and is handled
the same way, one layer up: `ChallengeCannotBeRaised`, thrown by the aggregate
when a chain asks for authentication on a deployment with no `ChallengePort`.

## Contents

| Class | Role |
| --- | --- |
| `Chain\ChainEvaluator` | evaluates one chain: hands the rules to its strategy, then raises the challenge a decision demanded |
| `Chain\ChainStrategy` | the traversal — which rules are visited, which answer wins, what an unmentioned subject gets |
| `Chain\FirstMatchWins` | the classic packet-filter walk; whitelist and blacklist are its two defaults |
| `Chain\RuleMatcher` | asks one rule whether it matches, so a strategy is about traversal only |
| `Rule\FirewallRule` | one rule: verdict + conditions + optional raw expression + id |
| `Rule\FirewallRuleSource` | supplies a chain's rules in order **and** its strategy — the seam that keeps storage out of the engine |
| `Common\Contract\FactSupplier` (shared kernel, not this package) | contributes a slice of the fact tree; no arguments, so this package learns no vendor vocabulary |
| `Fact\FactCollector` | merges suppliers and isolates their failures — a supplier that throws contributes nothing |
| `Dsl\RuleCompiler` | the only place the DSL becomes expression text |
| `Dsl\RuleEvaluator` | sandboxed evaluation and save-time validation |
| `Dsl\FactSchema` | the fact vocabulary: allowed roots plus declared types |
| `Dsl\FieldType` | declared type of a fact, used to coerce authored literals |
| `Dsl\ExpressionFunctions` | the function whitelist — widening it is a security decision |
