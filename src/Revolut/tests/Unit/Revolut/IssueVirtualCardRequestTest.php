<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Revolut\IssueVirtualCardRequest;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;

/**
 * @param  array<string, mixed>  $params
 */
function revolutIssueRequest(array $params = [], ?RevolutHttpClientInterface $client = null): IssueVirtualCardRequest
{
    $request = new IssueVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'revolutClient' => $client ?? Mockery::mock(RevolutHttpClientInterface::class),
        'money' => new Money(20022, new Currency('GBP')),
        'fetchSensitiveDetails' => false,
        ...$params,
    ]);

    return $request;
}

it('builds the create-card body with the required fields', function () {
    $data = revolutIssueRequest(['clientUniqueId' => 'req-1'])->getData();

    expect($data['request_id'])->toBe('req-1')
        ->and($data['virtual'])->toBeTrue()
        ->and($data)->not->toHaveKey('holder_id')
        ->and($data)->not->toHaveKey('label')
        ->and($data['spending_limits'])->toBe(['single' => ['amount' => 200.22, 'currency' => 'GBP']]);
});

it('generates a request id when no clientUniqueId is supplied', function () {
    $requestId = revolutIssueRequest()->getData()['request_id'];

    expect($requestId)->toBeString()->and($requestId)->not->toBe('');
});

it('maps a travel spend category onto the travel merchant control', function () {
    expect(revolutIssueRequest(['spendCategory' => 'travel_air'])->getData()['categories'])->toBe(['travel']);
});

it('omits categories for a spend category with no Revolut counterpart', function () {
    expect(revolutIssueRequest(['spendCategory' => 'medical'])->getData())->not->toHaveKey('categories');
});

it('omits categories when no spend category is supplied', function () {
    expect(revolutIssueRequest()->getData())->not->toHaveKey('categories');
});

it('includes only valid account uuids and omits accounts otherwise', function () {
    $a = '11111111-1111-1111-1111-111111111111';
    $b = '22222222-2222-2222-2222-222222222222';

    expect(revolutIssueRequest(['accountId' => [$a, $b]])->getData()['accounts'])->toBe([$a, $b])
        ->and(revolutIssueRequest()->getData())->not->toHaveKey('accounts')
        ->and(revolutIssueRequest(['accountId' => []])->getData())->not->toHaveKey('accounts')
        ->and(revolutIssueRequest(['accountId' => ['', 'not-a-uuid']])->getData())->not->toHaveKey('accounts')
        ->and(revolutIssueRequest(['accountId' => ['not-a-uuid', $a]])->getData()['accounts'])->toBe([$a]);
});

it('attaches a terminating spending period only when validity days are set', function () {
    $withPeriod = revolutIssueRequest(['validityDays' => 30])->getData();

    expect($withPeriod['spending_period']['end_date_action'])->toBe('terminate')
        ->and($withPeriod['spending_period'])->toHaveKey('end_date')
        ->and(revolutIssueRequest()->getData())->not->toHaveKey('spending_period')
        ->and(revolutIssueRequest(['validityDays' => 0])->getData())->not->toHaveKey('spending_period');
});

it('honours an overridden spend-limit period', function () {
    expect(revolutIssueRequest(['spendLimitPeriod' => 'month'])->getData()['spending_limits'])
        ->toBe(['month' => ['amount' => 200.22, 'currency' => 'GBP']]);
});

it('requires the spend limit money', function () {
    $request = new IssueVirtualCardRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'revolutClient' => Mockery::mock(RevolutHttpClientInterface::class),
    ]);

    $request->getData();
})->throws(InvalidRequestException::class);

it('creates the card then fetches sensitive details and maps the result', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('post')
        ->once()
        ->with('/api/1.0/cards', Mockery::on(fn (array $d): bool => $d['virtual'] === true))
        ->andReturn(['id' => 'card-1', 'last_digits' => '2671', 'expiry' => '09/2030', 'state' => 'active']);
    $client->shouldReceive('get')
        ->once()
        ->with('/api/1.0/cards/card-1/sensitive-details')
        ->andReturn(['pan' => '4111111111111111', 'cvv' => '123']);

    $response = revolutIssueRequest(['clientUniqueId' => 'req-1', 'fetchSensitiveDetails' => true], $client)->send();

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('card-1');

    $result = $response->toVirtualCardResult();

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('card-1')
        ->and($result->cardNumber)->toBe('4111111111111111')
        ->and($result->cvv)->toBe('123')
        ->and($result->expirationDate)->toBe('09/2030')
        ->and($result->status)->toBe('active');
});

it('does not fetch sensitive details when disabled', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andReturn(['id' => 'card-1', 'expiry' => '09/2030', 'state' => 'active']);
    $client->shouldNotReceive('get');

    $result = revolutIssueRequest(['fetchSensitiveDetails' => false], $client)->send()->toVirtualCardResult();

    expect($result->cardGuid)->toBe('card-1')
        ->and($result->cardNumber)->toBeNull()
        ->and($result->cvv)->toBeNull();
});

it('degrades gracefully when the sensitive-details lookup fails', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andReturn(['id' => 'card-1', 'expiry' => '09/2030', 'state' => 'active']);
    $client->shouldReceive('get')->once()->andThrow(new TransferException('IP not allow-listed'));

    $response = revolutIssueRequest(['fetchSensitiveDetails' => true], $client)->send();

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->toVirtualCardResult()->cardNumber)->toBeNull();
});

it('reports a failed result when card creation fails', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andThrow(new TransferException('quota exceeded'));

    $response = revolutIssueRequest([], $client)->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->toVirtualCardResult()->success)->toBeFalse()
        ->and($response->toVirtualCardResult()->message)->toContain('quota exceeded');
});
