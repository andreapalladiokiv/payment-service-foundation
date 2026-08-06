<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallVerdict;
use Techork\PaymentService\Firewall\Chain\ChainEvaluator;
use Techork\PaymentService\Firewall\Dsl\FactSchema;
use Techork\PaymentService\Firewall\Dsl\FieldType;
use Techork\PaymentService\Firewall\Dsl\RuleCompiler;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Firewall\Rule\FirewallRule;
use Techork\PaymentService\Firewall\Chain\ChainStrategy;
use Techork\PaymentService\Firewall\Chain\FirstMatchWins;
use Techork\PaymentService\Firewall\Exception\UnevaluableChain;
use Techork\PaymentService\Firewall\Rule\FirewallRuleSource;

/**
 * @param  array<int, FirewallRule>  $rules
 */
function firewallWith(array $rules, ?ChainStrategy $strategy = null): ChainEvaluator
{
    $schema = new class implements FactSchema
    {
        public function roots(): array
        {
            return ['card'];
        }

        public function typeOf(string $path): FieldType
        {
            return $path === 'card.is_proxy' ? FieldType::Boolean : FieldType::Text;
        }
    };

    // A whitelist by default: these tests are about the walk, and a fail-closed fallthrough is
    // the one that cannot make a broken walk look like a pass.
    $source = new readonly class($rules, $strategy ?? FirstMatchWins::whitelist()) implements FirewallRuleSource
    {
        /**
         * @param  array<int, FirewallRule>  $rules
         */
        public function __construct(private array $rules, private ChainStrategy $strategy) {}

        public function rulesFor(string $chain): iterable
        {
            return $this->rules;
        }

        public function strategyFor(string $chain): ChainStrategy
        {
            return $this->strategy;
        }
    };

    return new ChainEvaluator($source, new RuleEvaluator(new RuleCompiler($schema), $schema));
}

it('returns the first matching rule and stops there', function () {
    $decision = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, ['card.country' => ['values' => ['RU']]], id: '1'),
        new FirewallRule(FirewallVerdict::Allow, ['card.country' => ['values' => ['GB']]], id: '2'),
        new FirewallRule(FirewallVerdict::Deny, ['card.country' => ['values' => ['GB']]], id: '3'),
    ])->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->isAllowed())->toBeTrue()
        ->and($decision->reason)->toBe('matched rule 2');
});

it('asks the strategy what a subject its rules did not mention concludes', function (ChainStrategy $strategy, FirewallVerdict $expected) {
    // Was "falls through without inventing a verdict when nothing matches", which asserted a
    // NoMatch case that no longer exists. Silence was never a verdict a caller could act on, and
    // the one caller folded it in with a denial — so the chain answers instead, in the terms its
    // own strategy sets. `matched` stays false either way: this IS a real answer, and it is also
    // not one any rule gave.
    $decision = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, ['card.country' => ['values' => ['RU']]], id: '1'),
    ], $strategy)->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->verdict)->toBe($expected)
        ->and($decision->matched)->toBeFalse()
        ->and($decision->reason)->toBe('no rule matched ('.$strategy->name().')');
})->with([
    'whitelist denies' => [fn () => FirstMatchWins::whitelist(), FirewallVerdict::Deny],
    'blacklist allows' => [fn () => FirstMatchWins::blacklist(), FirewallVerdict::Allow],
]);

it('treats an empty chain as a fall-through, so an unauthored chain still answers', function () {
    // Worth its own case: a deployment that installed the engine and has written no rules yet gets
    // the strategy's answer rather than an error, and under a blacklist that means business as
    // usual — which is the trade a blacklist makes and the reason to notice an empty one.
    expect(firewallWith([], FirstMatchWins::blacklist())->evaluate('authorization', ['card' => ['country' => 'GB']])->isAllowed())->toBeTrue()
        ->and(firewallWith([], FirstMatchWins::whitelist())->evaluate('authorization', ['card' => ['country' => 'GB']])->isDenied())->toBeTrue();
});

it('matches a catch-all rule, which is how a chain closes with a default', function () {
    $decision = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, ['card.country' => ['values' => ['RU']]], id: '1'),
        new FirewallRule(FirewallVerdict::Deny, id: 'default'),
    ])->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->reason)->toBe('matched rule default');
});

it('refuses to answer at all when a rule is unevaluable', function () {
    // Was "skips an unevaluable rule instead of breaking the caller". Not breaking the caller was
    // the stated goal and the actual cost: the walk carried on, a `degraded` flag recorded that
    // something had been skipped, and interpreting it was left to callers who did not. Breaking
    // the caller is now the point — a broken rule is an operator's emergency, not a quiet discount
    // on protection.
    $chain = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, null, 'this is not ( valid', id: 'broken'),
        new FirewallRule(FirewallVerdict::Deny, ['card.country' => ['values' => ['RU']]], id: '2'),
    ]);

    expect(fn () => $chain->evaluate('authorization', ['card' => ['country' => 'GB']]))
        ->toThrow(UnevaluableChain::class);
});

it('refuses a chain whose matcher references a root the schema does not declare', function () {
    // The same abort through a different failure: the rule is syntactically fine and asks about a
    // fact the sandbox has no such root for. Previously skipped, so a rule written against a
    // renamed fact silently stopped applying.
    $chain = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, ['ssn' => ['values' => ['x']]], id: 'bad-root'),
    ]);

    expect(fn () => $chain->evaluate('authorization', ['card' => ['country' => 'GB']]))
        ->toThrow(UnevaluableChain::class, 'bad-root');
});

it('aborts the whole chain when a rule cannot be evaluated', function () {
    // Was "logs which rule was skipped and why". Skipping was the hole: a Deny rule with a typo
    // sitting above an Allow rule that matched produced a decision indistinguishable from a clean
    // pass, and the `degraded` flag that said otherwise was left for callers to interpret — which
    // the only caller did not. A chain that cannot be evaluated has no answer, so it refuses to
    // give one.
    //
    // The exception names the chain and the rule, because an operator reading it has to find the
    // broken rule among however many are in the chain, and carries the original as `previous`.
    $broken = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, null, 'nope (', id: 'broken'),
    ]);

    expect(fn () => $broken->evaluate('authorization', ['card' => []]))
        ->toThrow(UnevaluableChain::class, 'rule broken failed');
});

it('aborts even when a later rule would have matched, which is the hole this closed', function () {
    // The exact shape of the old fail-open. The broken rule denies, the rule below it allows and
    // does match — so skipping produced an allowed payment that no evaluated rule had allowed.
    $chain = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, null, 'nope (', id: 'broken-deny'),
        new FirewallRule(FirewallVerdict::Allow, ['card.country' => ['values' => ['GB']]], id: 'permits'),
    ]);

    expect(fn () => $chain->evaluate('authorization', ['card' => ['country' => 'GB']]))
        ->toThrow(UnevaluableChain::class, 'broken-deny');
});

it('says which chain could not be evaluated, not only which rule', function () {
    $chain = firewallWith([new FirewallRule(FirewallVerdict::Deny, null, 'nope (', id: 'broken')]);

    expect(fn () => $chain->evaluate('card_registration', ['card' => []]))
        ->toThrow(UnevaluableChain::class, 'card_registration');
});

// ─────────────────────────────────────────────────────────
//  Challenge, the third action
// ─────────────────────────────────────────────────────────

it('reports a challenge verdict and does not try to carry it out', function () {
    // The engine used to raise the step-up too, through an initiator it was handed. It could not:
    // what it holds are the facts the rules matched on, which carry a BIN and a last four and
    // deliberately never a card number, while authenticating a cardholder needs the pan, the
    // expiry and the holder. Widening the facts to supply them would have turned the vocabulary an
    // operator writes rules in into an argument list for one protocol.
    //
    // So the chain answers, and the aggregate that holds the instrument does the rest through
    // ChallengePort. What this pins is the boundary: a third action comes back like the other two,
    // and nothing about a challenge artefact appears in the decision.
    $decision = firewallWith([
        new FirewallRule(FirewallVerdict::Challenge, ['card.country' => ['values' => ['GB']]], id: 'step-up'),
    ])->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->requiresChallenge())->toBeTrue()
        ->and($decision->permits())->toBeFalse()
        ->and($decision->isDenied())->toBeFalse()
        ->and($decision->matched)->toBeTrue()
        ->and($decision->reason)->toBe('matched rule step-up');
});

it('answers a challenge fallthrough the same way, for a chain that steps up the unrecognised', function () {
    // Reached without any rule matching, so it also pins that the verdict survives the strategy's
    // default path rather than only the matched one.
    $decision = firewallWith([], FirstMatchWins::withDefault(FirewallVerdict::Challenge, 'step-up-unknown'))
        ->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->requiresChallenge())->toBeTrue()
        ->and($decision->matched)->toBeFalse()
        ->and($decision->reason)->toBe('no rule matched (step-up-unknown)');
});
