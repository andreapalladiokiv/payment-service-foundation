<?php

declare(strict_types=1);

use Money\Money;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CardSummary;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\IpAddress;
use Techork\PaymentService\Domain\Firewall\FirewallDecision;
use Techork\PaymentService\Domain\Firewall\FirewallVerdict;
use Techork\PaymentService\Domain\PaymentIntent\Port\NullPaymentIntentFirewall;
use Techork\PaymentService\Domain\PaymentIntent\Port\PaymentIntentFirewallPort;
use Techork\PaymentService\Domain\PaymentIntent\Port\Request\PaymentIntentFirewallRequest;

function paymentIntentFirewallRequest(?string $gatewayId = 'gw-1'): PaymentIntentFirewallRequest
{
    return new PaymentIntentFirewallRequest(
        amount: Money::USD(15000),
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(6, 2031), new Holder('A B')),
        billing: new BillingAddress('Ada', 'Lovelace', '1 Main St', 'London', new Country('GB'), 'E1 6AN'),
        connection: new ConnectionContext(new IpAddress('203.0.113.7'), 'Mozilla/5.0'),
        gatewayId: $gatewayId,
    );
}

it('is typed to the aggregate data, so the caller hands over no fact bag', function () {
    $firewall = new class implements PaymentIntentFirewallPort
    {
        public function evaluate(PaymentIntentFirewallRequest $request): FirewallDecision
        {
            // Everything the implementation needs is on the request. Which chain
            // to walk is not on it: this port IS the payment-intent chain.
            return $request->gatewayId === 'gw-1'
                ? FirewallDecision::deny('matched rule 7')
                : FirewallDecision::noMatch('no rule matched');
        }
    };

    expect($firewall->evaluate(paymentIntentFirewallRequest())->isDenied())->toBeTrue()
        ->and($firewall->evaluate(paymentIntentFirewallRequest())->reason)->toBe('matched rule 7')
        ->and($firewall->evaluate(paymentIntentFirewallRequest(null))->matched())->toBeFalse();
});

it('evaluates the chain even when there is no connection to inspect', function () {
    // Skipping because an input is absent is how a firewall silently stops
    // protecting anything, so a null connection is still evaluated.
    $firewall = new class implements PaymentIntentFirewallPort
    {
        public function evaluate(PaymentIntentFirewallRequest $request): FirewallDecision
        {
            return $request->connection === null
                ? FirewallDecision::deny('matched rule: no origin')
                : FirewallDecision::allow('matched rule 2');
        }
    };

    $request = new PaymentIntentFirewallRequest(
        amount: Money::USD(15000),
        card: new CardSummary('411111', '1111', CardBrand::Visa, Expiration::fromMonthAndYear(6, 2031), new Holder('A B')),
        billing: new BillingAddress('Ada', 'Lovelace', '1 Main St', 'London', new Country('GB'), 'E1 6AN'),
    );

    expect($firewall->evaluate($request)->isDenied())->toBeTrue();
});

it('denies by default when no firewall is installed', function () {
    $decision = (new NullPaymentIntentFirewall)->evaluate(paymentIntentFirewallRequest());

    expect($decision->isDenied())->toBeTrue()
        ->and($decision->verdict)->toBe(FirewallVerdict::Deny)
        ->and($decision->reason)->toBe('firewall not installed');
});

it('distinguishes falling through a chain from allowing', function () {
    $fellThrough = FirewallDecision::noMatch('no rule matched');
    $allowed = FirewallDecision::allow('matched rule 3');

    expect($fellThrough->matched())->toBeFalse()
        ->and($fellThrough->verdict)->toBe(FirewallVerdict::NoMatch)
        ->and($fellThrough->isAllowed())->toBeFalse()
        ->and($fellThrough->isDenied())->toBeFalse()
        ->and($allowed->matched())->toBeTrue()
        ->and($allowed->isAllowed())->toBeTrue();
});

it('reports a degraded chain on every outcome so skipping cannot pass as clean', function () {
    expect(FirewallDecision::noMatch(degraded: true)->degraded)->toBeTrue()
        ->and(FirewallDecision::allow(degraded: true)->degraded)->toBeTrue()
        ->and(FirewallDecision::deny(degraded: true)->degraded)->toBeTrue()
        ->and(FirewallDecision::noMatch()->degraded)->toBeFalse();
});

it('makes falling through a chain a verdict of its own, not an absent value', function () {
    expect(FirewallVerdict::cases())->toHaveCount(3)
        ->and(FirewallVerdict::NoMatch->value)->toBe('no_match');

    // A caller must say what each of the three means; there is no value to
    // silently default. This is the shape that forces the question:
    $policy = static fn (FirewallDecision $d): string => match ($d->verdict) {
        FirewallVerdict::Deny => 'step up',
        FirewallVerdict::Allow => 'proceed',
        FirewallVerdict::NoMatch => 'apply my own default',
    };

    expect($policy(FirewallDecision::deny()))->toBe('step up')
        ->and($policy(FirewallDecision::allow()))->toBe('proceed')
        ->and($policy(FirewallDecision::noMatch()))->toBe('apply my own default');
});

it('states the permit policy once, in the domain, and fails closed everywhere', function () {
    expect(FirewallDecision::allow('matched rule 3')->permits())->toBeTrue()
        // Everything that is not an explicit accept is refused.
        ->and(FirewallDecision::deny('matched rule 7')->permits())->toBeFalse()
        ->and(FirewallDecision::noMatch('no rule matched')->permits())->toBeFalse()
        // ...including an accept whose chain could not be fully evaluated: that
        // is the shape a fail-open hole takes.
        ->and(FirewallDecision::allow('matched rule 3', degraded: true)->permits())->toBeFalse();
});
