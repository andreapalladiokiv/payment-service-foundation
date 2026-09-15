<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\UpdateVirtualCard;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\ValueObject\CardLimitWindow;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function cpUpdateCard(
    Money $limit = new Money(2500, new Currency('USD')),
    CardSpendCategory $category = CardSpendCategory::TravelGeneric,
    ?ConnexPayHttpClientInterface $client = null,
): UpdateVirtualCard {
    return new UpdateVirtualCard(
        cpSettings(),
        new UpdateCardCommand(GatewayId::generate(), 'card-guid-xyz', $limit, $category),
        $client ?? cpHttpClient(),
    );
}

it('builds the update body with formatted AmountLimit and zero-padded PurchaseType', function () {
    expect(cpUpdateCard()->payload())->toBe([
        'AmountLimit' => 25.0,
        'PurchaseType' => '06',
    ]);
});

/*
 * Three tests are gone and cannot come back. They said the request throws when
 * `transactionReference`, `money` or `spendCategory` is missing — all three are now required
 * constructor arguments of {@see UpdateCardCommand}, typed, so there is no state in which one is
 * absent for the operation to notice.
 */

it('sends PUT to /api/v1/IssueCard/{cardGuid} and reports the card it updated', function () {
    $client = cpHttpClient();
    $client->shouldReceive('put')
        ->once()
        ->with('/api/v1/IssueCard/card-guid-xyz', [
            'AmountLimit' => 50.0,
            'PurchaseType' => '01',
        ])
        ->andReturn([]);

    $result = cpUpdateCard(new Money(5000, new Currency('USD')), CardSpendCategory::TravelAir, $client)->update();

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('card-guid-xyz');
});

/**
 * PUT does not echo the card payload; success is HTTP 200 with an empty body and failure is a
 * body carrying `error`.
 */
it('marks failure when the body carries an error', function () {
    $client = cpHttpClient();
    $client->shouldReceive('put')->once()->andReturn(['error' => 'Card is already terminated']);

    $result = cpUpdateCard(client: $client)->update();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Card is already terminated');
});

it('returns a failed result on a transport error', function () {
    $client = cpHttpClient();
    $client->shouldReceive('put')->once()->andThrow(new TransferException('Network error'));

    $result = cpUpdateCard(client: $client)->update();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Network error');
});

// ──────────────────────────────────────────────
//  lodged
// ──────────────────────────────────────────────

/**
 * `LimitWindow` exists in the lodged update schema and in no other, which is what makes the window
 * a sound discriminator for this one endpoint pair: sending it to the ordinary endpoint would drop
 * it silently, and a spend control silently dropped is the one outcome an update must not have.
 */
it('routes to the lodged endpoint when a limit window is named', function () {
    $client = cpHttpClient();
    $client->shouldReceive('put')
        ->once()
        ->with('/api/v1/IssueCard/LodgedCard/card-guid-xyz', [
            'AmountLimit' => 25.0,
            'PurchaseType' => '06',
            'LimitWindow' => 'MONTH',
        ])
        ->andReturn([]);

    $command = new UpdateCardCommand(
        GatewayId::generate(),
        'card-guid-xyz',
        new Money(2500, new Currency('USD')),
        CardSpendCategory::TravelGeneric,
        CardLimitWindow::Month,
    );

    expect(new UpdateVirtualCard(cpSettings(), $command, $client)->update())
        ->success->toBeTrue()
        ->cardGuid->toBe('card-guid-xyz');
});

it('keeps the ordinary endpoint and omits the window when none is named', function () {
    expect(cpUpdateCard()->path())->toBe('/api/v1/IssueCard/card-guid-xyz')
        ->and(cpUpdateCard()->payload())->not->toHaveKey('LimitWindow');
});
