<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\ConnexPayGateway;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\IncomingTransactionCode;
use Techork\PaymentService\ConnexPay\IssueLodgedCard;
use Techork\PaymentService\ConnexPay\IssueVirtualCard;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\ValueObject\CardLimitWindow;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @param  array<string, mixed>  $overrides
 */
function cpLodged(array $overrides = [], ?ConnexPayHttpClientInterface $client = null): IssueLodgedCard
{
    return new IssueLodgedCard(
        cpSettings(['merchantGuid' => '5e2a814b-accc-4473-8d02-44b807200336']),
        IssueCardCommand::balanceFunded(
            gatewayId: GatewayId::generate(),
            amountLimit: $overrides['money'] ?? new Money(5000, new Currency('USD')),
            spendCategory: $overrides['spendCategory'] ?? CardSpendCategory::TravelAir,
            limitWindow: array_key_exists('limitWindow', $overrides) ? $overrides['limitWindow'] : CardLimitWindow::Day,
            firstName: array_key_exists('firstName', $overrides) ? $overrides['firstName'] : 'Test',
            lastName: array_key_exists('lastName', $overrides) ? $overrides['lastName'] : 'User',
            cardBrand: $overrides['cardBrand'] ?? null,
            clientUniqueId: $overrides['clientUniqueId'] ?? null,
        ),
        $client ?? cpHttpClient(),
    );
}

/**
 * Trimmed from the vendor's own worked example for `POST /api/v1/IssueCard/LodgedCard`. Note what
 * it does NOT carry, against the sale-funded response: no `isLodged` and no `availableBalance`, so
 * a lodged card cannot be told from an ordinary one by its issuance answer, nor its balance read
 * from it.
 *
 * @return array<string, mixed>
 */
function cpLodgedSuccessPayload(): array
{
    return [
        'card' => [
            'cardGuid' => 'c67514a0-3110-4731-98b0-38858f282ade',
            'accountNumber' => '1111117027148580',
            'securityCode' => '818',
            'amountLimit' => 50.0,
            'usageLimit' => 1,
            'limitWindow' => 'DAY',
            'expirationDate' => '2029-12-30T23:59:59',
            'expiration' => '1229',
            'currencyCode' => 'USD',
            'firstSix' => '111111',
            'lastFour' => '8580',
            'status' => 'Card - Active',
        ],
        'cardBrand' => 'Visa',
        'cardClass' => 'Commercial',
        'saleGuid' => 'd49e4881-34e5-461f-9d43-0efb0d94039d',
    ];
}

// ──────────────────────────────────────────────
//  payload
// ──────────────────────────────────────────────

it('builds the lodged body with the six fields the vendor marks required', function () {
    $data = cpLodged()->payload();

    expect($data['MerchantGuid'])->toBe('5e2a814b-accc-4473-8d02-44b807200336')
        ->and($data['AmountLimit'])->toBe(50.0)
        ->and($data['FirstName'])->toBe('Test')
        ->and($data['LastName'])->toBe('User')
        ->and($data['PurchaseType'])->toBe('01')
        ->and($data['LimitWindow'])->toBe('DAY')
        ->and($data['ReturnCardData'])->toBeTrue();
});

/**
 * The one structural difference from `POST /api/v1/IssueCard`, and the reason a lodged card cannot
 * simply be sent to that endpoint with a null code: the field is not in this schema, because a
 * lodged card draws on the merchant's balance and there is no sale to name.
 */
it('never sends an IncomingTransactionCode', function () {
    expect(cpLodged()->payload())->not->toHaveKey('IncomingTransactionCode');
});

it('maps each shared window onto the vendor spelling observed on the wire', function (CardLimitWindow $window, string $expected) {
    expect(cpLodged(['limitWindow' => $window])->payload()['LimitWindow'])->toBe($expected);
})->with([
    [CardLimitWindow::Day, 'DAY'],
    [CardLimitWindow::Week, 'WEEK'],
    [CardLimitWindow::Month, 'MONTH'],
    [CardLimitWindow::Lifetime, 'LIFETIME'],
]);

/**
 * Revolut falls back to a configured period when a card names none; ConnexPay has no such setting
 * and the vendor lists `LimitWindow` in `required`, so the absence is refused rather than guessed
 * at. A default period would be a decision about how much money the card may spend per span.
 */
it('refuses to issue without a limit window', function () {
    cpLodged(['limitWindow' => null])->payload();
})->throws(InvalidArgumentException::class, 'require a limit window');

it('carries the brand and the identifiers the same way the sale-funded endpoint does', function () {
    $data = cpLodged(['cardBrand' => CardBrand::Visa, 'clientUniqueId' => 'ORD-42'])->payload();

    expect($data['CardBrand'])->toBe('Visa')
        ->and($data['OrderNumber'])->toBe('ORD-42')
        ->and(cpLodged()->payload())->not->toHaveKey('CardBrand');
});

it('defaults the cardholder name when the caller named nobody', function () {
    $data = cpLodged(['firstName' => null, 'lastName' => null])->payload();

    expect($data['FirstName'])->toBe('N/A')
        ->and($data['LastName'])->toBe('N/A');
});

// ──────────────────────────────────────────────
//  issue
// ──────────────────────────────────────────────

it('posts to the lodged path and maps the card with the MMYY expiration', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/IssueCard/LodgedCard', Mockery::on(fn (array $d): bool => $d['LimitWindow'] === 'DAY'))
        ->andReturn(cpLodgedSuccessPayload());

    $result = cpLodged([], $client)->issue();

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('c67514a0-3110-4731-98b0-38858f282ade')
        ->and($result->cardNumber)->toBe('1111117027148580')
        ->and($result->cvv)->toBe('818')
        ->and($result->expirationDate)->toBe('1229')
        ->and($result->status)->toBe('Card - Active');
});

it('marks a missing cardGuid as failure', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn(['card' => ['cardGuid' => null, 'status' => 'Card - Rejected']]);

    expect(cpLodged([], $client)->issue())
        ->success->toBeFalse()
        ->message->toBe('Card - Rejected');
});

/**
 * Deliberately unlike {@see IssueVirtualCard}, which reports a
 * transport failure as a card-shaped json body to preserve what merchants already read. This
 * endpoint has no callers yet, so there is nothing to preserve and no reason to start by hiding
 * the error.
 */
it('reports a transport failure as itself', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Network error'));

    expect(cpLodged([], $client)->issue())
        ->success->toBeFalse()
        ->message->toBe('Network error');
});

// ──────────────────────────────────────────────
//  routing
// ──────────────────────────────────────────────

/**
 * The funding model chooses the endpoint, and nothing infers it. Two endpoints that do not
 * overlap: the sale-funded one declares `IncomingTransactionCode` and refuses a request with no
 * sale to name, the lodged one has no such field. Substituting one for the other is a 400, not a
 * degradation.
 */
function cpCardGateway(ConnexPayHttpClientInterface $purchases): ConnexPayGateway
{
    $gateway = new ConnexPayGateway;
    $gateway->configure(cpInfrastructure(['settings' => [
        'username' => 'u',
        'password' => 'p',
        'merchantGuid' => 'm-1',
    ]]));
    $gateway->setHttpClient(cpHttpClient(), $purchases);

    return $gateway;
}

it('sends a balance-funded card to the lodged endpoint', function () {
    $purchases = cpHttpClient();
    $purchases->shouldReceive('post')
        ->once()
        ->with('/api/v1/IssueCard/LodgedCard', Mockery::any())
        ->andReturn(cpLodgedSuccessPayload());

    $result = cpCardGateway($purchases)->issueVirtualCard(IssueCardCommand::balanceFunded(
        gatewayId: GatewayId::generate(),
        amountLimit: new Money(5000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
        limitWindow: CardLimitWindow::Day,
    ));

    expect($result->success)->toBeTrue();
});

/**
 * The ConnexPay-owned hint is unwrapped here and nowhere else — the common layer carries it as an
 * opaque slot. Carrying it is what spares the Search/Sales fallback whose guid filters ConnexPay
 * silently ignores.
 */
it('sends a sale-funded card to the ordinary endpoint with the hinted code', function () {
    $purchases = cpHttpClient();
    $purchases->shouldReceive('post')
        ->once()
        ->with('/api/v1/IssueCard', Mockery::on(
            fn (array $d): bool => ($d['IncomingTransactionCode'] ?? null) === 'ITC-9',
        ))
        ->andReturn(cpIssueCardSuccessPayload());

    $result = cpCardGateway($purchases)->issueVirtualCard(IssueCardCommand::saleFunded(
        gatewayId: GatewayId::generate(),
        transactionReference: 'sale-guid',
        amountLimit: new Money(5000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
        hint: new IncomingTransactionCode('ITC-9'),
    ));

    expect($result->success)->toBeTrue();
});
