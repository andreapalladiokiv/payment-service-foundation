<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallVerdict;
use Techork\PaymentService\Firewall\Chain\ChainStrategy;
use Techork\PaymentService\Firewall\Chain\FirstMatchWins;
use Techork\PaymentService\Firewall\Chain\RuleMatcher;
use Techork\PaymentService\Firewall\Dsl\FactSchema;
use Techork\PaymentService\Firewall\Dsl\FieldType;
use Techork\PaymentService\Firewall\Dsl\RuleCompiler;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Firewall\Rule\FirewallRule;

/**
 * A strategy owns the traversal, so what these tests pin is the traversal itself: which rules get
 * visited, which answer wins, and what an unmentioned subject gets.
 *
 * {@see FirstMatchWins} is the only traversal that ships, and what is worth pinning about it is
 * exactly what a different one would change: that rule order decides the outcome, that everything
 * below a match goes unread, and that an unmentioned subject gets the chain's default. A chain
 * authored against those assumptions and evaluated by another walk quietly means something else,
 * which is why they are stated rather than left to the reader of the loop.
 *
 * These drive real rules through a real matcher rather than a double, because a strategy's whole
 * job is deciding which rules to ask about, and a double that answers every question makes exactly
 * that invisible.
 */
function strategyMatcher(array $facts = ['card' => ['country' => 'GB']]): RuleMatcher
{
    $schema = new class implements FactSchema
    {
        public function roots(): array
        {
            return ['card'];
        }

        public function typeOf(string $path): FieldType
        {
            return FieldType::Text;
        }
    };

    return new RuleMatcher(new RuleEvaluator(new RuleCompiler($schema), $schema), 'authorization', $facts);
}

/**
 * @return array<int, FirewallRule>
 */
function strategyRules(FirewallVerdict ...$verdicts): array
{
    $rules = [];

    foreach ($verdicts as $i => $verdict) {
        // Every rule matches, so what a test observes is the traversal alone and never the matching.
        $rules[] = new FirewallRule($verdict, ['card.country' => ['values' => ['GB']]], id: (string) ($i + 1));
    }

    return $rules;
}

// ─────────────────────────────────────────────────────────
//  FirstMatchWins — the classic packet-filter walk
// ─────────────────────────────────────────────────────────

it('stops at the first match and never reads what is below it', function () {
    // What makes rule order meaningful, and what lets a narrow exception sit above a broad
    // prohibition. The two rules below disagree; the higher one is the answer.
    $decision = FirstMatchWins::whitelist()->walk(
        strategyRules(FirewallVerdict::Allow, FirewallVerdict::Deny),
        strategyMatcher(),
    );

    expect($decision->isAllowed())->toBeTrue()
        ->and($decision->matched)->toBeTrue()
        ->and($decision->reason)->toBe('matched rule 1');
});

it('answers an unmentioned subject with its default, and says which default that was', function (ChainStrategy $strategy, FirewallVerdict $expected, string $name) {
    // Whitelist and blacklist are this same walk with opposite defaults — the traversal is
    // identical and only the answer for a subject no rule described differs. The name lands in the
    // reason because a fallthrough denial and a denial by an explicit rule read identically after
    // the fact.
    $decision = $strategy->walk(
        [new FirewallRule(FirewallVerdict::Deny, ['card.country' => ['values' => ['RU']]], id: 'other')],
        strategyMatcher(),
    );

    expect($decision->verdict)->toBe($expected)
        ->and($decision->matched)->toBeFalse()
        ->and($decision->reason)->toBe("no rule matched ({$name})");
})->with([
    'whitelist denies' => [fn () => FirstMatchWins::whitelist(), FirewallVerdict::Deny, 'whitelist'],
    'blacklist allows' => [fn () => FirstMatchWins::blacklist(), FirewallVerdict::Allow, 'blacklist'],
]);

it('carries a rule verdict of any of the three through unchanged', function (FirewallVerdict $verdict) {
    // The strategy decides WHICH rule answers, not what its answer means. A challenge rule must
    // not be flattened into a denial on the way out.
    $decision = FirstMatchWins::blacklist()->walk(strategyRules($verdict), strategyMatcher());

    expect($decision->verdict)->toBe($verdict);
})->with([FirewallVerdict::Allow, FirewallVerdict::Deny, FirewallVerdict::Challenge]);

it('accepts a default that is neither posture, for a chain that challenges the unrecognised', function () {
    // The reason `withDefault` exists rather than an enum of two: a chain may want to step up
    // anything its rules do not describe, which is neither whitelist nor blacklist.
    $decision = FirstMatchWins::withDefault(FirewallVerdict::Challenge, 'challenge-unknown')
        ->walk([], strategyMatcher());

    expect($decision->requiresChallenge())->toBeTrue()
        ->and($decision->reason)->toBe('no rule matched (challenge-unknown)');
});

// ─────────────────────────────────────────────────────────
//  What every traversal owes its caller
// ─────────────────────────────────────────────────────────

it('always answers with one of the three actions and never with nothing', function (ChainStrategy $strategy) {
    // The property that makes a caller's job possible, and the one that stops the removed NoMatch
    // from returning under a new name: there is no null and no fourth case, on any strategy, for
    // any chain — including an empty one.
    expect($strategy->walk([], strategyMatcher())->verdict)->toBeInstanceOf(FirewallVerdict::class);
})->with([
    'whitelist' => fn () => FirstMatchWins::whitelist(),
    'blacklist' => fn () => FirstMatchWins::blacklist(),
]);

it('names itself for the audit trail', function (ChainStrategy $strategy, string $expected) {
    expect($strategy->name())->toBe($expected);
})->with([
    'whitelist' => [fn () => FirstMatchWins::whitelist(), 'whitelist'],
    'blacklist' => [fn () => FirstMatchWins::blacklist(), 'blacklist'],
]);

it('reports that a challenge is required and stops there', function (ChainStrategy $strategy) {
    // A strategy decides which rule answers; nothing in this package carries the answer out. The
    // engine was briefly handed an initiator to raise the step-up itself, and could not do the
    // job: the facts it works from hold a BIN and a last four and never a card number, which is
    // not enough to authenticate anybody. So the verdict travels and the aggregate that holds the
    // instrument acts on it.
    $decision = $strategy->walk(strategyRules(FirewallVerdict::Challenge), strategyMatcher());

    expect($decision->requiresChallenge())->toBeTrue()
        ->and($decision->permits())->toBeFalse();
})->with([
    'first match wins' => fn () => FirstMatchWins::blacklist(),
]);
