<?php

declare(strict_types=1);

use Symfony\Component\ExpressionLanguage\SyntaxError;
use Techork\PaymentService\Firewall\Dsl\FactSchema;
use Techork\PaymentService\Firewall\Dsl\FieldType;
use Techork\PaymentService\Firewall\Dsl\RuleCompiler;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;

/**
 * Stands in for a domain value object nested inside the facts: it has a public
 * property AND behaviour, so it can prove the behaviour does not survive.
 */
final class FirewallFactObjectStub
{
    public string $country = 'GB';

    public function detonate(): string
    {
        return 'reached domain behaviour';
    }
}

beforeEach(function () {
    $schema = new class implements FactSchema
    {
        public function roots(): array
        {
            return ['card', 'order'];
        }

        public function typeOf(string $path): FieldType
        {
            return match ($path) {
                'card.is_prepaid' => FieldType::Boolean,
                'card.success_rate', 'order.amount' => FieldType::Number,
                default => FieldType::Text,
            };
        }
    };

    $this->evaluator = new RuleEvaluator(new RuleCompiler($schema), $schema);
});

it('resolves dot-paths into nested facts', function () {
    $facts = ['card' => ['country' => 'GB', 'brand' => 'visa']];

    expect($this->evaluator->matches(['card.country' => ['values' => ['GB']]], $facts))->toBeTrue()
        ->and($this->evaluator->matches(['card.country' => ['values' => ['US']]], $facts))->toBeFalse();
});

it('matches a numeric fact regardless of how the literal was authored', function () {
    // Authored as text, and the fact is a float — a strict comparison would miss.
    expect($this->evaluator->matches(
        ['card.success_rate' => ['values' => ['95']]],
        ['card' => ['success_rate' => 95.0]],
    ))->toBeTrue();
});

it('matches a boolean fact without inverting on the string "false"', function () {
    expect($this->evaluator->matches(
        ['card.is_prepaid' => ['values' => ['false']]],
        ['card' => ['is_prepaid' => false]],
    ))->toBeTrue()
        ->and($this->evaluator->matches(
            ['card.is_prepaid' => ['values' => ['false']]],
            ['card' => ['is_prepaid' => true]],
        ))->toBeFalse();
});

it('evaluates a range against a numeric fact', function () {
    $conditions = ['order.amount' => ['min' => '100', 'max' => '200']];

    expect($this->evaluator->matches($conditions, ['order' => ['amount' => 150]]))->toBeTrue()
        ->and($this->evaluator->matches($conditions, ['order' => ['amount' => 99]]))->toBeFalse()
        ->and($this->evaluator->matches($conditions, ['order' => ['amount' => 201]]))->toBeFalse();
});

it('evaluates the raw expression escape hatch, including fact-to-fact', function () {
    expect($this->evaluator->matches(null, [
        'card' => ['country' => 'GB'],
        'order' => ['country' => 'US'],
    ], 'card.country != order.country'))->toBeTrue();
});

it('treats a rule with nothing configured as a catch-all', function () {
    expect($this->evaluator->matches(null, []))->toBeTrue();
});

it('exposes only data to a rule, never domain behaviour', function () {
    $facts = ['card' => ['holder' => new FirewallFactObjectStub]];

    // The public property survives the JSON flatten...
    expect($this->evaluator->matches(['card.holder.country' => ['values' => ['GB']]], $facts))->toBeTrue();

    // ...but the object arrives as a plain stdClass, so its behaviour is gone
    // and a rule cannot reach into the domain through it.
    expect(fn (): bool => $this->evaluator->matches(null, $facts, 'card.holder.detonate() == "x"'))
        ->toThrow('Unable to call method "detonate" of object "stdClass"');
});

it('rejects an unknown fact root at authoring time', function () {
    $this->evaluator->validate(null, 'ssn != ""');
})->throws(SyntaxError::class);

it('accepts a known root and any sub-path at authoring time', function () {
    $this->evaluator->validate(
        ['card.deeply.nested' => ['values' => ['x']]],
        'order.amount >= 100',
    );
})->throwsNoExceptions();

it('rejects unparseable expression text at authoring time', function () {
    $this->evaluator->validate(null, 'card.country ===');
})->throws(SyntaxError::class);

it('rejects a function outside the whitelist at authoring time', function () {
    $this->evaluator->validate(null, 'file_get_contents("/etc/passwd") != ""');
})->throws(SyntaxError::class);

it('offers the whitelisted helpers', function () {
    $facts = ['card' => ['country' => 'GB', 'note' => '']];

    expect($this->evaluator->matches(null, $facts, 'includes(card.country, "G")'))->toBeTrue()
        ->and($this->evaluator->matches(null, $facts, 'is_empty(card.note)'))->toBeTrue()
        ->and($this->evaluator->matches(null, $facts, 'is_not_empty(card.country)'))->toBeTrue();
});
