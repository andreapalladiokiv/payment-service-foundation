<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallVerdict;
use Techork\PaymentService\Firewall\Chain\ChainEvaluator;
use Techork\PaymentService\Firewall\Dsl\FactSchema;
use Techork\PaymentService\Firewall\Dsl\FieldType;
use Techork\PaymentService\Firewall\Dsl\RuleCompiler;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Firewall\Rule\FirewallRule;
use Techork\PaymentService\Firewall\Rule\FirewallRuleSource;

/**
 * @param  array<int, FirewallRule>  $rules
 */
function firewallWith(array $rules): ChainEvaluator
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

    $source = new class($rules) implements FirewallRuleSource
    {
        /**
         * @param  array<int, FirewallRule>  $rules
         */
        public function __construct(private readonly array $rules) {}

        public function rulesFor(string $chain): iterable
        {
            return $this->rules;
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

it('falls through without inventing a verdict when nothing matches', function () {
    $decision = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, ['card.country' => ['values' => ['RU']]], id: '1'),
    ])->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->matched())->toBeFalse()
        ->and($decision->verdict)->toBe(FirewallVerdict::NoMatch)
        ->and($decision->reason)->toBe('no rule matched')
        ->and($decision->degraded)->toBeFalse();
});

it('treats an empty chain as a fall-through', function () {
    $decision = firewallWith([])->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->matched())->toBeFalse();
});

it('matches a catch-all rule, which is how a chain closes with a default', function () {
    $decision = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, ['card.country' => ['values' => ['RU']]], id: '1'),
        new FirewallRule(FirewallVerdict::Deny, id: 'default'),
    ])->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->reason)->toBe('matched rule default');
});

it('skips an unevaluable rule instead of breaking the caller', function () {
    $decision = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, null, 'this is not ( valid', id: 'broken'),
        new FirewallRule(FirewallVerdict::Deny, ['card.country' => ['values' => ['RU']]], id: '2'),
    ])->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->matched())->toBeFalse()
        ->and($decision->degraded)->toBeTrue();
});

it('reports degradation on a match too, so a skipped deny cannot pass as clean', function () {
    // The dangerous shape: a broken deny sitting above an allow that matches.
    $decision = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, null, 'this is not ( valid', id: 'broken'),
        new FirewallRule(FirewallVerdict::Allow, ['card.country' => ['values' => ['GB']]], id: '2'),
    ])->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->isAllowed())->toBeTrue()
        ->and($decision->degraded)->toBeTrue();
});

it('skips a rule whose matcher references an unknown root', function () {
    $decision = firewallWith([
        new FirewallRule(FirewallVerdict::Deny, ['ssn' => ['values' => ['x']]], id: 'bad-root'),
    ])->evaluate('authorization', ['card' => ['country' => 'GB']]);

    expect($decision->matched())->toBeFalse()
        ->and($decision->degraded)->toBeTrue();
});

it('logs which rule was skipped and why', function () {
    $logged = [];
    $logger = new class($logged) extends Psr\Log\AbstractLogger
    {
        public function __construct(private array &$logged) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->logged[] = [$level, (string) $message, $context];
        }
    };

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

    $source = new class implements FirewallRuleSource
    {
        public function rulesFor(string $chain): iterable
        {
            return [new FirewallRule(FirewallVerdict::Deny, null, 'nope (', id: 'broken')];
        }
    };

    (new ChainEvaluator($source, new RuleEvaluator(new RuleCompiler($schema), $schema), $logger))
        ->evaluate('authorization', ['card' => []]);

    expect($logged)->toHaveCount(1)
        ->and($logged[0][0])->toBe('error')
        ->and($logged[0][2]['rule'])->toBe('broken')
        ->and($logged[0][2]['chain'])->toBe('authorization');
});
