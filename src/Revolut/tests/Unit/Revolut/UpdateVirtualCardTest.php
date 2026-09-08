<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Revolut\CardSettings;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;
use Techork\PaymentService\Revolut\UpdateVirtualCard;

function revolutUpdating(?RevolutHttpClientInterface $client = null, string $period = 'single'): UpdateVirtualCard
{
    return new UpdateVirtualCard(
        $client ?? Mockery::mock(RevolutHttpClientInterface::class),
        new CardSettings(spendLimitPeriod: $period),
    );
}

function revolutUpdateCommand(?CardSpendCategory $category = null): UpdateCardCommand
{
    return new UpdateCardCommand(
        GatewayId::generate(),
        'card-1',
        new Money(50000, new Currency('GBP')),
        $category ?? CardSpendCategory::Restaurants,
    );
}

/*
 * `it('requires money and the card reference')` lived here. Both are constructor arguments on
 * {@see UpdateCardCommand}, so the state it described cannot be built.
 */

it('builds the patch body with the updated spend limit', function () {
    expect(revolutUpdating()->payload(revolutUpdateCommand())['spending_limits'])
        ->toBe(['single' => ['amount' => 500.00, 'currency' => 'GBP']]);
});

it('includes categories when a mappable spend category is supplied', function () {
    expect(revolutUpdating()->payload(revolutUpdateCommand(CardSpendCategory::Restaurants))['categories'])
        ->toBe(['restaurants']);
});

it('patches the card and maps the updated state', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('patch')
        ->once()
        ->with('/api/1.0/cards/card-1', Mockery::on(fn (array $d): bool => isset($d['spending_limits'])))
        ->andReturn(['id' => 'card-1', 'state' => 'active', 'expiry' => '09/2030']);

    $result = revolutUpdating($client)->update(revolutUpdateCommand());

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('card-1')
        ->and($result->status)->toBe('active');
});

it('falls back to the requested card id when the patch body is sparse', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('patch')->once()->andReturn([]);

    expect(revolutUpdating($client)->update(revolutUpdateCommand())->cardGuid)->toBe('card-1');
});

it('reports a failed result when the patch fails', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('patch')->once()->andThrow(new TransferException('card terminated'));

    $result = revolutUpdating($client)->update(revolutUpdateCommand());

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('card terminated');
});
