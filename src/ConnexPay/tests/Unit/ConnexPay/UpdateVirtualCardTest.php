<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\UpdateVirtualCard;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
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
