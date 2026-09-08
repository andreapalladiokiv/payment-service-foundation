<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\IssueVirtualCard;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @param  array<string, mixed>  $overrides
 */
function cpIssue(array $overrides = [], ?ConnexPayHttpClientInterface $client = null): IssueVirtualCard
{
    return new IssueVirtualCard(
        cpSettings(['merchantGuid' => '5e2a814b-accc-4473-8d02-44b807200336']),
        new IssueCardCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'sale-guid',
            amountLimit: $overrides['money'] ?? new Money(5000, new Currency('USD')),
            spendCategory: $overrides['spendCategory'] ?? CardSpendCategory::TravelAir,
            firstName: array_key_exists('firstName', $overrides) ? $overrides['firstName'] : 'Test',
            lastName: array_key_exists('lastName', $overrides) ? $overrides['lastName'] : 'User',
            cardBrand: $overrides['cardBrand'] ?? null,
            clientUniqueId: $overrides['clientUniqueId'] ?? null,
        ),
        $client ?? cpHttpClient(),
        $overrides['incomingTransactionCode'] ?? '5406E46639136547414675607',
    );
}

/**
 * Payload below is a real ConnexPay sandbox response for POST /api/v1/IssueCard captured on
 * 2026-05-06.
 *
 * Note: ConnexPay returns BOTH `expirationDate` (ISO datetime) and `expiration` (MMYY string).
 * We use the MMYY format because the downstream `card_expiration` column is char(4).
 *
 * @return array<string, mixed>
 */
function cpIssueCardSuccessPayload(): array
{
    return [
        'card' => [
            'cardGuid' => 'c6303e46-55a4-43b4-b50d-c7993f0d7c4f',
            'accountNumber' => '5190754485992771',
            'securityCode' => '366',
            'amountLimit' => 5.0,
            'expirationDate' => '2029-05-06T00:00:00Z',
            'expiration' => '0529',
            'currencyCode' => 'USD',
            'firstSix' => '519075',
            'lastFour' => '2771',
            'status' => 'Card - Active',
            'cardClass' => 'CommercialCredit',
        ],
        'cardBrand' => 'MasterCard',
        'saleGuid' => 'f3a4ea0b-a36b-4c04-83c9-e030428a325c',
        'incomingTransactionCode' => '5406E46639136547414675607',
    ];
}

// ──────────────────────────────────────────────
//  payload
// ──────────────────────────────────────────────

it('omits CardBrand when none is requested', function () {
    expect(cpIssue()->payload())->not->toHaveKey('CardBrand');
});

it('translates the domain brand to ConnexPay PascalCase', function () {
    expect(cpIssue(['cardBrand' => CardBrand::Visa])->payload()['CardBrand'])->toBe('Visa')
        ->and(cpIssue(['cardBrand' => CardBrand::Mastercard])->payload()['CardBrand'])->toBe('Mastercard');
});

it('throws on unsupported brand', function () {
    cpIssue(['cardBrand' => CardBrand::Amex])->payload();
})->throws(InvalidArgumentException::class, 'Unsupported ConnexPay card brand: amex');

it('forwards clientUniqueId as OrderNumber and omits it when absent', function () {
    expect(cpIssue(['clientUniqueId' => 'ORD-42'])->payload()['OrderNumber'])->toBe('ORD-42')
        ->and(cpIssue()->payload())->not->toHaveKey('OrderNumber');
});

it('builds the full request body with required fields', function () {
    $data = cpIssue(['cardBrand' => CardBrand::Visa])->payload();

    expect($data['MerchantGuid'])->toBe('5e2a814b-accc-4473-8d02-44b807200336')
        ->and($data['AmountLimit'])->toBe(50.0)
        ->and($data['FirstName'])->toBe('Test')
        ->and($data['LastName'])->toBe('User')
        ->and($data['PurchaseType'])->toBe('01')
        ->and($data['IncomingTransactionCode'])->toBe('5406E46639136547414675607')
        ->and($data['ReturnCardData'])->toBeTrue()
        ->and($data['CardBrand'])->toBe('Visa');
});

it('defaults the cardholder name when the caller named nobody', function () {
    $data = cpIssue(['firstName' => null, 'lastName' => null])->payload();

    expect($data['FirstName'])->toBe('N/A')
        ->and($data['LastName'])->toBe('N/A');
});

/*
 * One test is gone and cannot come back: "throws on an unknown CardSpendCategory". The category
 * is a {@see CardSpendCategory} on {@see IssueCardCommand}, so a value that is not one of them
 * cannot reach the operation — the string that used to be validated no longer exists.
 */

// ──────────────────────────────────────────────
//  issue
// ──────────────────────────────────────────────

it('maps an issued card to a VirtualCardResult with the MMYY expiration', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/IssueCard', Mockery::on(fn (array $d): bool => $d['ReturnCardData'] === true))
        ->andReturn(cpIssueCardSuccessPayload());

    $result = cpIssue([], $client)->issue();

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('c6303e46-55a4-43b4-b50d-c7993f0d7c4f')
        ->and($result->cardNumber)->toBe('5190754485992771')
        ->and($result->cvv)->toBe('366')
        ->and($result->expirationDate)->toBe('0529')
        ->and($result->status)->toBe('Card - Active');
});

it('marks a missing cardGuid as failure', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn(['card' => ['cardGuid' => null, 'status' => 'Card - Rejected']]);

    $result = cpIssue([], $client)->issue();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Card - Rejected');
});

it('treats an unparseable error envelope as failure with a json fallback', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn(['unexpected' => 'shape']);

    $result = cpIssue([], $client)->issue();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('{"unexpected":"shape"}');
});

/**
 * The message is the json of a card-shaped body rather than the transport error itself, and that
 * is pinned rather than fixed: it is what the merchant read before the conversion, because the
 * pre-conversion code built this same body and then fell through to the json fallback. Worth a
 * test precisely because it looks like a bug — so that improving it is a deliberate change with
 * its own reasoning, not a side effect of the next edit here.
 */
it('reports a transport failure the way it always has, as a json body', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Network error'));

    $result = cpIssue([], $client)->issue();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('{"cardGuid":null,"status":"Network error"}');
});
