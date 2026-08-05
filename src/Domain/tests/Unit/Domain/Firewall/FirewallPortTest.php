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
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallDecision;
use Techork\PaymentService\Domain\PaymentIntent\Port\FirewallVerdict;
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
                : FirewallDecision::allow('no rule matched (blacklist)', matched: false);
        }
    };

    expect($firewall->evaluate(paymentIntentFirewallRequest())->isDenied())->toBeTrue()
        ->and($firewall->evaluate(paymentIntentFirewallRequest())->reason)->toBe('matched rule 7')
        ->and($firewall->evaluate(paymentIntentFirewallRequest(null))->matched)->toBeFalse();
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

it('allows by default when no firewall is installed, because a stub is not a policy', function () {
    // Was "denies by default", reasoning from `iptables -P INPUT DROP`. Wrong analogy: a packet
    // filter's default policy is a configured decision, while this is the ABSENCE of one — the
    // optional rule engine is not installed, so there is no chain, no strategy and nothing to
    // apply. Denying turned "the operator has not wired up an optional package" into a step-up on
    // every payment, which is a self-inflicted outage dressed as caution.
    //
    // `matched` is false because no rule said this: it is the shape of an empty chain under a
    // blacklist, which is what "no firewall" honestly is.
    $decision = new NullPaymentIntentFirewall()->evaluate(paymentIntentFirewallRequest());

    expect($decision->isAllowed())->toBeTrue()
        ->and($decision->permits())->toBeTrue()
        ->and($decision->matched)->toBeFalse()
        ->and($decision->reason)->toBe('firewall not installed');
});

it('separates a decision a rule made from one the chain fell through to', function () {
    // Both are real answers — that is the whole change from the old NoMatch, which was neither an
    // answer nor an absence. What is still worth telling apart is WHO decided, because "allowed
    // because a rule said so" and "allowed because nothing objected" read identically after the
    // fact and mean different things when a chargeback arrives.
    $fellThrough = FirewallDecision::allow('no rule matched (blacklist)', matched: false);
    $byRule = FirewallDecision::allow('matched rule 3');

    expect($fellThrough->matched)->toBeFalse()
        ->and($fellThrough->isAllowed())->toBeTrue()
        ->and($byRule->matched)->toBeTrue()
        ->and($byRule->isAllowed())->toBeTrue();
});

it('offers three actions and no way to answer nothing', function () {
    // Was "makes falling through a chain a verdict of its own". It was a verdict of its own and
    // that was the problem: it obliged every caller to invent a policy, and the one caller there
    // is folded it in with a denial and fabricated a 3DS challenge for both. Now each case is an
    // action a caller can carry out, and silence is resolved by the chain's strategy before it
    // ever reaches here.
    expect(FirewallVerdict::cases())->toHaveCount(3)
        ->and(array_map(fn (FirewallVerdict $v): string => $v->value, FirewallVerdict::cases()))
        ->toBe(['allow', 'deny', 'challenge']);

    $act = static fn (FirewallDecision $d): string => match ($d->verdict) {
        FirewallVerdict::Allow => 'proceed',
        FirewallVerdict::Deny => 'refuse the payment',
        FirewallVerdict::Challenge => 'require a step-up',
    };

    expect($act(FirewallDecision::allow()))->toBe('proceed')
        ->and($act(FirewallDecision::deny()))->toBe('refuse the payment')
        ->and($act(FirewallDecision::challenge()))->toBe('require a step-up');
});

it('permits only an allow, and a challenge is not a smaller allow', function () {
    // A challenge permits AFTER it is passed, which is a different payment state, so it must not
    // read as permission here. This used to also have to exclude a degraded accept; a chain that
    // cannot be evaluated now throws instead of answering, so there is no such case left to state.
    expect(FirewallDecision::allow('matched rule 3')->permits())->toBeTrue()
        ->and(FirewallDecision::deny('matched rule 7')->permits())->toBeFalse()
        ->and(FirewallDecision::challenge('matched rule 9')->permits())->toBeFalse()
        ->and(FirewallDecision::challenge('matched rule 9')->requiresChallenge())->toBeTrue();
});

it('carries the challenge that was raised, or admits none was', function () {
    // The distinction the aggregate acts on. A challenge object is evidence that a handoff to an
    // ACS already happened; when no integration raised one, null says "required, not started" —
    // which is why the aggregate no longer has to invent a ThreeDSChallenge to have something to
    // record.
    $raised = new ThreeDSChallenge(transactionId: 'ds-txn-1', acsUrl: 'https://acs.example/challenge', creq: 'creq');

    expect(FirewallDecision::challenge('matched rule 9', challenge: $raised)->challenge)->toBe($raised)
        ->and(FirewallDecision::challenge('matched rule 9')->challenge)->toBeNull()
        ->and(FirewallDecision::challenge('matched rule 9')->withChallenge($raised)->challenge)->toBe($raised);
});
