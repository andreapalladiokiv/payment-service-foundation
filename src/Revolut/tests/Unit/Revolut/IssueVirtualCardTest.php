<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Revolut\CardSettings;
use Techork\PaymentService\Revolut\IssueVirtualCard;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;

/**
 * Two shapes of input, kept apart: settings are what the deployment configured, the command is
 * what the caller asked for. They used to arrive in one array, which is why the old request had
 * accessors that could not say where a value had come from.
 *
 * @param  array<string, mixed>  $settings
 */
function revolutIssuing(?RevolutHttpClientInterface $client = null, array $settings = []): IssueVirtualCard
{
    return new IssueVirtualCard(
        $client ?? Mockery::mock(RevolutHttpClientInterface::class),
        new CardSettings(
            product: $settings['product'] ?? null,
            accountIds: $settings['accountIds'] ?? null,
            spendLimitPeriod: $settings['spendLimitPeriod'] ?? 'single',
            validityDays: $settings['validityDays'] ?? null,
            fetchSensitiveDetails: $settings['fetchSensitiveDetails'] ?? false,
        ),
    );
}

/**
 * @param  array<string, mixed>  $params
 */
function revolutIssueCommand(array $params = []): IssueCardCommand
{
    return new IssueCardCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'sale-guid',
        amountLimit: $params['money'] ?? new Money(20022, new Currency('GBP')),
        spendCategory: $params['spendCategory'] ?? CardSpendCategory::TravelAir,
        clientUniqueId: $params['clientUniqueId'] ?? null,
    );
}

/*
 * Three tests are gone and cannot come back. Two described a spend category that was unrecognised
 * or absent — {@see IssueCardCommand} types it as a {@see CardSpendCategory}, so neither state can
 * be handed to the operation. The third said a missing spend limit throws: the amount is a
 * constructor argument, not a parameter to forget.
 */

it('builds the create-card body with the required fields', function () {
    $body = revolutIssuing()->payload(revolutIssueCommand(['clientUniqueId' => 'req-1']));

    expect($body['request_id'])->toBe('req-1')
        ->and($body['virtual'])->toBeTrue()
        ->and($body)->not->toHaveKey('holder_id')
        ->and($body)->not->toHaveKey('label')
        ->and($body['spending_limits'])->toBe(['single' => ['amount' => 200.22, 'currency' => 'GBP']]);
});

it('generates a request id when no clientUniqueId is supplied', function () {
    $requestId = revolutIssuing()->payload(revolutIssueCommand())['request_id'];

    expect($requestId)->toBeString()->and($requestId)->not->toBe('');
});

it('maps a travel spend category onto its merchant controls', function () {
    expect(revolutIssuing()->payload(revolutIssueCommand(['spendCategory' => CardSpendCategory::TravelAir]))['categories'])
        ->toBe(['airlines'])
        ->and(revolutIssuing()->payload(revolutIssueCommand(['spendCategory' => CardSpendCategory::TravelGeneric]))['categories'])
        ->toBe(['airlines', 'accommodation', 'transport']);
});

it('includes only valid account uuids and omits accounts otherwise', function () {
    $a = '11111111-1111-1111-1111-111111111111';
    $b = '22222222-2222-2222-2222-222222222222';

    expect(revolutIssuing(settings: ['accountIds' => [$a, $b]])->payload(revolutIssueCommand())['accounts'])->toBe([$a, $b])
        ->and(revolutIssuing()->payload(revolutIssueCommand()))->not->toHaveKey('accounts')
        ->and(revolutIssuing(settings: ['accountIds' => []])->payload(revolutIssueCommand()))->not->toHaveKey('accounts')
        ->and(revolutIssuing(settings: ['accountIds' => ['', 'not-a-uuid']])->payload(revolutIssueCommand()))->not->toHaveKey('accounts')
        ->and(revolutIssuing(settings: ['accountIds' => ['not-a-uuid', $a]])->payload(revolutIssueCommand())['accounts'])->toBe([$a]);
});

it('includes the product code as an object only when configured', function () {
    expect(revolutIssuing(settings: ['product' => 'prod_123'])->payload(revolutIssueCommand())['product'])
        ->toBe(['code' => 'prod_123'])
        ->and(revolutIssuing()->payload(revolutIssueCommand()))->not->toHaveKey('product');
});

it('attaches a terminating spending period only when validity days are set', function () {
    $withPeriod = revolutIssuing(settings: ['validityDays' => 30])->payload(revolutIssueCommand());

    expect($withPeriod['spending_period']['end_date_action'])->toBe('terminate')
        ->and($withPeriod['spending_period'])->toHaveKey('end_date')
        ->and(revolutIssuing()->payload(revolutIssueCommand()))->not->toHaveKey('spending_period')
        ->and(revolutIssuing(settings: ['validityDays' => 0])->payload(revolutIssueCommand()))->not->toHaveKey('spending_period');
});

it('honours an overridden spend-limit period', function () {
    expect(revolutIssuing(settings: ['spendLimitPeriod' => 'month'])->payload(revolutIssueCommand())['spending_limits'])
        ->toBe(['month' => ['amount' => 200.22, 'currency' => 'GBP']]);
});

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

    $result = revolutIssuing($client, ['fetchSensitiveDetails' => true])
        ->issue(revolutIssueCommand(['clientUniqueId' => 'req-1']));

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('card-1')
        ->and($result->cardNumber)->toBe('4111111111111111')
        ->and($result->cvv)->toBe('123')
        ->and($result->expirationDate)->toBe('092030')
        ->and($result->status)->toBe('active');
});

it('does not fetch sensitive details when disabled', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andReturn(['id' => 'card-1', 'expiry' => '09/2030', 'state' => 'active']);
    $client->shouldNotReceive('get');

    $result = revolutIssuing($client)->issue(revolutIssueCommand());

    expect($result->cardGuid)->toBe('card-1')
        ->and($result->cardNumber)->toBeNull()
        ->and($result->cvv)->toBeNull();
});

/**
 * A card that exists must not be lost because the follow-up lookup was refused — the scope or the
 * IP allow-list is a deployment matter, and the card is already issued either way.
 */
it('degrades gracefully when the sensitive-details lookup fails', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andReturn(['id' => 'card-1', 'expiry' => '09/2030', 'state' => 'active']);
    $client->shouldReceive('get')->once()->andThrow(new TransferException('IP not allow-listed'));

    $result = revolutIssuing($client, ['fetchSensitiveDetails' => true])->issue(revolutIssueCommand());

    expect($result->success)->toBeTrue()
        ->and($result->cardNumber)->toBeNull();
});

it('reports a failed result when card creation fails', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('post')->once()->andThrow(new TransferException('quota exceeded'));

    $result = revolutIssuing($client)->issue(revolutIssueCommand());

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('quota exceeded');
});
