<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Revolut\Exception\UnsupportedOperationException;
use Techork\PaymentService\Revolut\IssueVirtualCardRequest;
use Techork\PaymentService\Revolut\TerminateCardRequest;
use Techork\PaymentService\Revolut\UpdateVirtualCardRequest;

it('has name revolut', function () {
    expect(makeRevolutGateway()->getName())->toBe('revolut');
});

it('creates an issue virtual card request', function () {
    expect(makeRevolutGateway()->issueVirtualCard())->toBeInstanceOf(IssueVirtualCardRequest::class);
});

it('creates an update virtual card request', function () {
    expect(makeRevolutGateway()->updateVirtualCard())->toBeInstanceOf(UpdateVirtualCardRequest::class);
});

it('creates a terminate card request', function () {
    expect(makeRevolutGateway()->terminateVirtualCard())->toBeInstanceOf(TerminateCardRequest::class);
});

it('throws on every acquiring / tokenization operation', function (string $operation) {
    makeRevolutGateway()->{$operation}();
})->throws(UnsupportedOperationException::class)->with([
    'purchase',
    'authorize',
    'capture',
    'refund',
    'void',
    'createCard',
    'createPaymentMethod',
]);

// The class alone is not the guarantee. Without the marker interface the router
// folds this into a failed result and the stream records PaymentIntentFailed /
// RefundFailed — i.e. it claims an issuer declined a payment that was never sent.
// Revolut acquires nothing, so every one of these is a misrouting, refund
// included: there is no retryRefund primitive here to degrade gracefully.
it('refuses acquiring operations as a wiring error, not as an acquirer decline', function () {
    expect(is_subclass_of(UnsupportedOperationException::class, UnsupportedByGateway::class))->toBeTrue();
});

it('throws something the router will rethrow rather than swallow', function (string $operation) {
    try {
        makeRevolutGateway()->{$operation}();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(UnsupportedByGateway::class);

        return;
    }

    $this->fail("Revolut::{$operation}() did not throw at all.");
})->with([
    'purchase',
    'authorize',
    'capture',
    'refund',
    'void',
    'createCard',
    'createPaymentMethod',
]);

it('always resolves to the production host (Revolut has no card sandbox)', function () {
    expect(makeRevolutGateway()->getResolvedBaseUrl())->toBe('https://b2b.revolut.com');
});

it('lets an explicit base URL override the production default', function () {
    expect(makeRevolutGateway(params: ['baseUrl' => 'https://proxy.internal'])->getResolvedBaseUrl())
        ->toBe('https://proxy.internal');
});

it('injects gateway-level card configuration into issued cards', function () {
    $account = '11111111-1111-1111-1111-111111111111';

    $request = makeRevolutGateway(params: [
        'accountIds' => [$account],
        'product' => 'prod_gw',
        'spendLimitPeriod' => 'month',
        'validityDays' => 14,
        'fetchSensitiveDetails' => false,
    ])->issueVirtualCard([
        'money' => new Money(5000, new Currency('GBP')),
        'clientUniqueId' => 'req-1',
    ]);

    $data = $request->getData();

    expect($data['accounts'])->toBe([$account])
        ->and($data['product'])->toBe(['code' => 'prod_gw'])
        ->and($data['spending_limits'])->toBe(['month' => ['amount' => 50.00, 'currency' => 'GBP']])
        ->and($data['spending_period']['end_date_action'])->toBe('terminate')
        ->and($request->getFetchSensitiveDetails())->toBeFalse();
});

it('tolerates a legacy single-string account id', function () {
    $account = '11111111-1111-1111-1111-111111111111';

    $request = makeRevolutGateway(params: ['accountIds' => $account])->issueVirtualCard([
        'money' => new Money(5000, new Currency('GBP')),
    ]);

    expect($request->getData()['accounts'])->toBe([$account]);
});

it('drops non-uuid account ids from the allow-list', function () {
    $account = '11111111-1111-1111-1111-111111111111';

    expect(makeRevolutGateway(params: ['accountIds' => ['not-a-uuid', $account]])
        ->issueVirtualCard(['money' => new Money(5000, new Currency('GBP'))])->getData()['accounts'])->toBe([$account])
        ->and(makeRevolutGateway(params: ['accountIds' => ['not-a-uuid']])
            ->issueVirtualCard(['money' => new Money(5000, new Currency('GBP'))])->getData())->not->toHaveKey('accounts');
});
