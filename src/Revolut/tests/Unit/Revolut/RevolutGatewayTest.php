<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
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

it('always resolves to the production host (Revolut has no card sandbox)', function () {
    expect(makeRevolutGateway()->getResolvedBaseUrl())->toBe('https://b2b.revolut.com');
});

it('lets an explicit base URL override the production default', function () {
    expect(makeRevolutGateway(params: ['baseUrl' => 'https://proxy.internal'])->getResolvedBaseUrl())
        ->toBe('https://proxy.internal');
});

it('injects gateway-level card configuration into issued cards', function () {
    $request = makeRevolutGateway(params: [
        'accountId' => ['acc-9'],
        'spendLimitPeriod' => 'month',
        'validityDays' => 14,
        'fetchSensitiveDetails' => false,
    ])->issueVirtualCard([
        'money' => new Money(5000, new Currency('GBP')),
        'clientUniqueId' => 'req-1',
    ]);

    $data = $request->getData();

    expect($data['accounts'])->toBe(['acc-9'])
        ->and($data['spending_limits'])->toBe(['month' => ['amount' => 50.00, 'currency' => 'GBP']])
        ->and($data['spending_period']['end_date_action'])->toBe('terminate')
        ->and($request->getFetchSensitiveDetails())->toBeFalse();
});

it('tolerates a legacy single-string account id', function () {
    $request = makeRevolutGateway(params: ['accountId' => 'acc-legacy'])->issueVirtualCard([
        'money' => new Money(5000, new Currency('GBP')),
    ]);

    expect($request->getData()['accounts'])->toBe(['acc-legacy']);
});
