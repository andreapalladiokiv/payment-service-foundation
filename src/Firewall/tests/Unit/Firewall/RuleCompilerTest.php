<?php

declare(strict_types=1);

use Techork\PaymentService\Firewall\Dsl\FactSchema;
use Techork\PaymentService\Firewall\Dsl\FieldType;
use Techork\PaymentService\Firewall\Dsl\RuleCompiler;

beforeEach(function () {
    $this->compiler = new RuleCompiler(new class implements FactSchema
    {
        public function roots(): array
        {
            return ['card', 'order'];
        }

        public function typeOf(string $path): FieldType
        {
            return match ($path) {
                'card.is_prepaid' => FieldType::Boolean,
                'order.amount' => FieldType::Number,
                default => FieldType::Text,
            };
        }
    });
});

it('compiles a single value as a one-element membership set', function () {
    expect($this->compiler->compile(['card.country' => ['values' => ['GB']]]))
        ->toBe('((card.country == "GB"))');
});

it('compiles several values as any-of', function () {
    expect($this->compiler->compile(['card.country' => ['values' => ['GB', 'US']]]))
        ->toBe('((card.country == "GB" or card.country == "US"))');
});

it('distributes negation as none-of, not any-of', function () {
    expect($this->compiler->compile(['card.country' => ['not' => true, 'values' => ['GB', 'US']]]))
        ->toBe('((card.country != "GB" and card.country != "US"))');
});

it('accepts a comma-separated value list', function () {
    expect($this->compiler->compile(['card.country' => ['values' => 'GB, US']]))
        ->toBe('((card.country == "GB" or card.country == "US"))');
});

it('ANDs every matcher together', function () {
    expect($this->compiler->compile([
        'card.country' => ['values' => ['GB']],
        'card.brand' => ['values' => ['visa']],
    ]))->toBe('((card.country == "GB") and (card.brand == "visa"))');
});

it('accepts the list form as well as the path-keyed map', function () {
    expect($this->compiler->compile([['field' => 'card.country', 'values' => ['GB']]]))
        ->toBe('((card.country == "GB"))');
});

it('coerces a literal to the declared fact type', function () {
    expect($this->compiler->compile(['card.is_prepaid' => ['values' => ['false']]]))
        ->toBe('((card.is_prepaid == false))')
        ->and($this->compiler->compile(['order.amount' => ['values' => ['4999', '10000.5']]]))
        ->toBe('((order.amount == 4999 or order.amount == 10000.5))');
});

it('compiles a range from either or both bounds', function () {
    expect($this->compiler->compile(['order.amount' => ['min' => '50', 'max' => '99']]))
        ->toBe('((order.amount >= 50 and order.amount <= 99))')
        ->and($this->compiler->compile(['order.amount' => ['min' => '5000']]))
        ->toBe('((order.amount >= 5000))')
        ->and($this->compiler->compile(['order.amount' => ['max' => '10000']]))
        ->toBe('((order.amount <= 10000))');
});

it('ANDs the raw expression onto the matchers', function () {
    expect($this->compiler->compile(['card.country' => ['values' => ['GB']]], 'order.amount >= 5000'))
        ->toBe('((card.country == "GB") and (order.amount >= 5000))');
});

it('compiles an expression-only rule', function () {
    expect($this->compiler->compile([], 'card.country != order.country'))
        ->toBe('((card.country != order.country))');
});

it('skips an unconfigured matcher', function () {
    expect($this->compiler->compile([
        'card.country' => ['values' => []],
        'card.brand' => ['values' => ['visa']],
    ]))->toBe('((card.brand == "visa"))');
});

it('compiles a rule with nothing configured to a catch-all', function () {
    expect($this->compiler->compile(null))->toBe('true')
        ->and($this->compiler->compile([]))->toBe('true')
        ->and($this->compiler->compile(['card.brand' => ['values' => '']], ''))->toBe('true');
});

it('rejects a fact outside the schema roots', function () {
    $this->compiler->compile(['ssn' => ['values' => ['x']]]);
})->throws(InvalidArgumentException::class, 'Unknown firewall rule fact root: ssn');

it('allows any sub-path beneath a known root', function () {
    expect($this->compiler->compile(['card.deeply.nested.thing' => ['values' => ['x']]]))
        ->toBe('((card.deeply.nested.thing == "x"))');
});

it('rejects a non-numeric range bound', function () {
    $this->compiler->compile(['order.amount' => ['min' => 'abc']]);
})->throws(InvalidArgumentException::class, 'Firewall rule range bounds must be numeric.');

it('rejects a matcher that is not an object', function () {
    $this->compiler->compile(['card.country' => 'GB']);
})->throws(InvalidArgumentException::class, 'Each firewall rule matcher must be an object.');

it('rejects a matcher with no field', function () {
    $this->compiler->compile([['values' => ['GB']]]);
})->throws(InvalidArgumentException::class, 'Firewall rule matcher is missing a field.');

it('treats a zero bound as a bound, not as an absent one', function () {
    // Zero is a legitimate limit ("a BIN that never succeeds"); only null and the
    // empty string mean "no bound". Collapsing zero to absent once turned this
    // rule into the literal `true` — a catch-all matching every transaction.
    expect($this->compiler->compile(['order.amount' => ['max' => 0]]))
        ->toBe('((order.amount <= 0))')
        ->and($this->compiler->compile(['order.amount' => ['max' => '0']]))
        ->toBe('((order.amount <= 0))')
        ->and($this->compiler->compile(['order.amount' => ['min' => 0, 'max' => 50]]))
        ->toBe('((order.amount >= 0 and order.amount <= 50))');
});

it('treats only null and an empty string as an absent bound', function () {
    expect($this->compiler->compile(['order.amount' => ['min' => null, 'max' => null]]))->toBe('true')
        ->and($this->compiler->compile(['order.amount' => ['min' => '', 'max' => '']]))->toBe('true');
});
