<?php

declare(strict_types=1);

use Money\Money;
use Techork\PaymentService\Common\Contract\FactSupplier;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\IpAddress;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallVerdict;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;
use Techork\PaymentService\Firewall\Chain\ChainEvaluator;
use Techork\PaymentService\Firewall\Dsl\RuleCompiler;
use Techork\PaymentService\Firewall\Dsl\RuleEvaluator;
use Techork\PaymentService\Firewall\PaymentIntent\EnrichmentSuppliers;
use Techork\PaymentService\Firewall\PaymentIntent\PaymentIntentFactSchema;
use Techork\PaymentService\Firewall\PaymentIntent\PaymentIntentFirewall;
use Techork\PaymentService\Firewall\PaymentIntent\RequestFactSupplier;
use Techork\PaymentService\Firewall\Rule\FirewallRule;
use Techork\PaymentService\Firewall\Rule\FirewallRuleSource;

function firewallRequestFor(bool $withConnection = true, ?string $gatewayId = 'gw-1'): PaymentIntentFirewallRequest
{
    return new PaymentIntentFirewallRequest(
        amount: Money::USD(15000),
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(6, 2031), new Holder('A B')),
        billing: new BillingAddress('Ada', 'Lovelace', '1 Main St', 'London', new Country('GB'), 'E1 6AN'),
        connection: $withConnection
            ? new ConnectionContext(new IpAddress('203.0.113.7'), 'Mozilla/5.0', 'device-1')
            : null,
        gatewayId: $gatewayId,
    );
}

/**
 * @param  array<string, mixed>  $facts
 */
function supplierOf(array $facts): FactSupplier
{
    return new class($facts) implements FactSupplier
    {
        /** @param array<string, mixed> $facts */
        public function __construct(private readonly array $facts) {}

        public function facts(): array
        {
            return $this->facts;
        }
    };
}

/**
 * @param  array<int, FirewallRule>  $rules
 * @param  array<int, FactSupplier>  $enrichment
 */
function paymentIntentFirewall(array $rules, array $enrichment = []): PaymentIntentFirewall
{
    $schema = new PaymentIntentFactSchema;

    $source = new class($rules) implements FirewallRuleSource
    {
        /** @param array<int, FirewallRule> $rules */
        public function __construct(private readonly array $rules) {}

        public function rulesFor(string $chain): iterable
        {
            return $chain === PaymentIntentFirewall::CHAIN ? $this->rules : [];
        }
    };

    $suppliers = new class($enrichment) implements EnrichmentSuppliers
    {
        /** @param array<int, FactSupplier> $enrichment */
        public function __construct(private readonly array $enrichment) {}

        public function for(PaymentIntentFirewallRequest $request): iterable
        {
            return $this->enrichment;
        }
    };

    return new PaymentIntentFirewall(
        new ChainEvaluator($source, new RuleEvaluator(new RuleCompiler($schema), $schema)),
        $suppliers,
    );
}

it('matches a rule against facts taken from the domain request alone', function () {
    $decision = paymentIntentFirewall([
        new FirewallRule(FirewallVerdict::Deny, ['payment_method.source.bin' => ['values' => ['411111']]], id: '1'),
    ])->evaluate(firewallRequestFor());

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->reason)->toBe('matched rule 1');
});

it('matches on the transaction, not only the instrument', function () {
    $decision = paymentIntentFirewall([
        new FirewallRule(FirewallVerdict::Deny, ['payment_intent.amount' => ['min' => '10000']], id: 'big'),
    ])->evaluate(firewallRequestFor());

    expect($decision->isDenied())->toBeTrue();
});

it('lets an enrichment supplier contribute facts the request could not know', function () {
    // The screening verdict is a fact, so a rule can weigh it rather than it
    // being decided ahead of the rules.
    $decision = paymentIntentFirewall(
        [new FirewallRule(FirewallVerdict::Deny, ['screening.is_declined' => ['values' => ['true']]], id: 'screened')],
        [supplierOf(['screening' => ['is_declined' => true]])],
    )->evaluate(firewallRequestFor());

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->reason)->toBe('matched rule screened');
});

it('lets enrichment override what the request asserted, not the reverse', function () {
    // A looked-up issuer country is better evidence than anything the caller
    // supplied, so enrichment is layered after the request.
    $decision = paymentIntentFirewall(
        [new FirewallRule(FirewallVerdict::Allow, ['payment_method.source.bin' => ['values' => ['999999']]], id: 'overridden')],
        [supplierOf(['payment_method' => ['source' => ['bin' => '999999']]])],
    )->evaluate(firewallRequestFor());

    expect($decision->isAllowed())->toBeTrue();
});

it('evaluates the chain for a merchant-initiated request that has no origin', function () {
    // A null connection must not make the question disappear: the branch is
    // present with null fields so a rule can ask about it.
    $decision = paymentIntentFirewall(
        [new FirewallRule(FirewallVerdict::Deny, null, 'payment_method.connection.ip == null', id: 'no-origin')],
    )->evaluate(firewallRequestFor(withConnection: false));

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->reason)->toBe('matched rule no-origin');
});

it('falls through when nothing matches, leaving the policy to the caller', function () {
    $decision = paymentIntentFirewall([
        new FirewallRule(FirewallVerdict::Deny, ['payment_method.source.bin' => ['values' => ['555555']]], id: '1'),
    ])->evaluate(firewallRequestFor());

    expect($decision->verdict)->toBe(FirewallVerdict::NoMatch)
        ->and($decision->permits())->toBeFalse();
});

it('reports a degraded chain when a rule could not be evaluated', function () {
    $decision = paymentIntentFirewall([
        new FirewallRule(FirewallVerdict::Deny, null, 'this is not ( valid', id: 'broken'),
        new FirewallRule(FirewallVerdict::Allow, ['payment_method.source.bin' => ['values' => ['411111']]], id: '2'),
    ])->evaluate(firewallRequestFor());

    expect($decision->isAllowed())->toBeTrue()
        ->and($decision->degraded)->toBeTrue()
        // ...and the domain refuses it anyway, which is the point of the flag.
        ->and($decision->permits())->toBeFalse();
});

it('survives an enrichment supplier that fails, evaluating on what is known', function () {
    $exploding = new class implements FactSupplier
    {
        public function facts(): array
        {
            throw new RuntimeException('provider is down');
        }
    };

    $decision = paymentIntentFirewall(
        [new FirewallRule(FirewallVerdict::Deny, ['payment_method.source.bin' => ['values' => ['411111']]], id: '1')],
        [$exploding],
    )->evaluate(firewallRequestFor());

    expect($decision->isDenied())->toBeTrue();
});

it('exposes exactly the roots its schema declares', function () {
    $facts = (new RequestFactSupplier(firewallRequestFor()))->facts();

    expect(array_keys($facts))->toBe(['payment_method', 'payment_intent'])
        // screening only appears when a supplier provides it, but the schema
        // must already allow it or a rule referencing it could not be saved.
        ->and((new PaymentIntentFactSchema)->roots())->toContain('screening');
});

it('stringifies value objects so a rule reaches data and never behaviour', function () {
    $facts = (new RequestFactSupplier(firewallRequestFor()))->facts();

    expect($facts['payment_method']['billing_address']['country'])->toBe('GB')
        ->and($facts['payment_method']['connection']['ip'])->toBe('203.0.113.7')
        ->and($facts['payment_method']['connection']['has_device_token'])->toBeTrue()
        ->and($facts['payment_intent']['amount'])->toBe(15000)
        ->and($facts['payment_intent']['currency'])->toBe('USD')
        ->and($facts['payment_method']['source']['expiry_year'])->toBe(2031);
});
