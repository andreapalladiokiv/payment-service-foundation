<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\Decorator\CardIssuerFailureBoundary;
use Techork\PaymentService\Gateway\Decorator\LoggingCardIssuer;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;
use Techork\PaymentService\Gateway\Role\CardIssuer;
use Techork\PaymentService\Gateway\ValueObject\CardLimitWindow;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\SaleFundingHint;

/**
 * The issuing stack. Same shape as the acquiring one and deliberately separate: the providers
 * barely overlap, so a card operation folds into a card result and never into a payment outcome.
 */
function issueCommand(): IssueCardCommand
{
    return IssueCardCommand::saleFunded(
        gatewayId: GatewayId::generate(),
        transactionReference: 'sale-guid',
        amountLimit: new Money(5000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
        clientUniqueId: 'pi-1:card',
    );
}

function updateCommand(): UpdateCardCommand
{
    return new UpdateCardCommand(GatewayId::generate(), 'card-guid', new Money(3000, new Currency('USD')), CardSpendCategory::TravelAir);
}

/**
 * Runs an issuance through the logging decorator and returns the lines it wrote, keyed by message.
 *
 * The funding assertions below are about what the decorator writes, so they go through it rather
 * than through anything the command or the value objects could answer on their own — those have no
 * opinion about log lines any more.
 *
 * @return array<string, array<string, mixed>>
 */
function issuedLines(IssueCardCommand $command): array
{
    $lines = [];
    $logger = Mockery::mock(GatewayLoggerInterface::class);
    $logger->shouldReceive('log')->andReturnUsing(function (string $message, array $context) use (&$lines): void {
        $lines[$message] = $context;
    });

    $inner = Mockery::mock(CardIssuer::class);
    $inner->shouldReceive('issueVirtualCard')->andReturn(VirtualCardResult::succeeded('card-1'));

    new LoggingCardIssuer($inner, $logger, 'connexpay')->issueVirtualCard($command);

    return $lines;
}

/*
 * Five tests of `CardResultAssembler::card()` lived here: a provider response was folded into a
 * VirtualCardResult by reading `isSuccessful()`, `getMessage()` and a `toVirtualCardResult()`
 * capability off it. There is no shared folder and no response object left to fold — a card
 * operation builds its own result — so what those cases pinned now lives in each provider's
 * operation tests (Revolut's IssueVirtualCardTest, ConnexPay's CreateCardTest). What stays here
 * is what is genuinely shared: the boundary, the logging and the proxy.
 */

// ────────────────────────────── decorators

it('folds a thrown provider error into a failed card result', function () {
    $inner = Mockery::mock(CardIssuer::class);
    $inner->shouldReceive('issueVirtualCard')->andThrow(new RuntimeException('issuer unavailable'));

    expect(new CardIssuerFailureBoundary($inner)->issueVirtualCard(issueCommand()))
        ->success->toBeFalse()
        ->message->toBe('issuer unavailable');
});

/**
 * Four of five providers issue nothing, so this is the operation most likely to be misrouted and
 * the marker is what stops that being recorded as a card the issuer refused.
 */
it('rethrows a marked refusal from every card operation', function (string $operation) {
    $inner = Mockery::mock(CardIssuer::class);
    $inner->shouldReceive($operation)->andThrow(
        UnsupportedOperation::forGateway('nuvei', $operation, 'it issues no cards'),
    );

    $boundary = new CardIssuerFailureBoundary($inner);

    $command = match ($operation) {
        'issueVirtualCard' => issueCommand(),
        'updateVirtualCard' => updateCommand(),
        default => new TerminateCardCommand(GatewayId::generate(), 'card-guid'),
    };

    $thrown = null;

    try {
        $boundary->{$operation}($command);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class);
})->with(['issueVirtualCard', 'updateVirtualCard', 'terminateVirtualCard']);

it('terminates to a bare outcome, since there is no card left to describe', function () {
    $inner = Mockery::mock(CardIssuer::class);
    $inner->shouldReceive('terminateVirtualCard')->andReturn(GatewayResult::succeeded('void-1'));

    expect(new CardIssuerFailureBoundary($inner)->terminateVirtualCard(
        new TerminateCardCommand(GatewayId::generate(), 'card-guid'),
    ))->toBeInstanceOf(GatewayResult::class);
});

/**
 * The card's secrets are not logged. The router's hand-written response array carried
 * `cardNumber` and `cvv`, leaving a downstream sanitiser as the only thing between a PAN and the
 * log file; the line written here names neither, and this is what notices if a later edit does.
 */
it('logs a card operation without the number or the cvv', function () {
    $lines = [];
    $logger = Mockery::mock(GatewayLoggerInterface::class);
    $logger->shouldReceive('log')->andReturnUsing(function (string $message, array $context) use (&$lines): void {
        $lines[$message] = $context;
    });

    $inner = Mockery::mock(CardIssuer::class);
    $inner->shouldReceive('issueVirtualCard')->andReturn(
        VirtualCardResult::succeeded('card-1', '4111111111111111', '123', '12/30', 'Active'),
    );

    new LoggingCardIssuer($inner, $logger, 'connexpay')->issueVirtualCard(issueCommand());

    expect($lines['Gateway issueVirtualCard request'])
        ->toHaveKey('gatewayName', 'connexpay')
        ->toHaveKey('transactionReference', 'sale-guid')
        // `->value`, where the router logged the enum object and it rendered as {"name":…,"value":…}.
        ->toHaveKey('spendCategory', CardSpendCategory::TravelAir->value)
        ->and($lines['Gateway issueVirtualCard response'])
        ->toHaveKey('cardGuid', 'card-1')
        ->not->toHaveKey('cardNumber')
        ->not->toHaveKey('cvv');
});

/**
 * The funding pair on the request line, which is where its asymmetry shows.
 *
 * A sale-funded card names the sale it draws on; a balance-funded card emits no such key at all —
 * not a null one, so a reader has nothing to find empty. The hint is named by its CLASS and never
 * unwrapped: whatever a gateway put inside belongs to that gateway, and this line is one every
 * gateway writes.
 */
it('names the funding model, and the sale only when there is one', function () {
    $hint = new class implements SaleFundingHint {};

    $lines = issuedLines(IssueCardCommand::saleFunded(
        gatewayId: GatewayId::generate(),
        transactionReference: 'sale-guid',
        amountLimit: new Money(5000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
        limitWindow: CardLimitWindow::Month,
        hint: $hint,
    ));

    expect($lines['Gateway issueVirtualCard request'])
        ->toHaveKey('fundingModel', 'sale')
        ->toHaveKey('transactionReference', 'sale-guid')
        ->toHaveKey('fundingHint', $hint::class)
        ->toHaveKey('limitWindow', CardLimitWindow::Month->value)
        ->and(json_encode($lines))->not->toContain('fundingHint":"value');
});

it('omits the transaction reference entirely on a balance-funded card', function () {
    $lines = issuedLines(IssueCardCommand::balanceFunded(
        gatewayId: GatewayId::generate(),
        amountLimit: new Money(5000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
    ));

    expect($lines['Gateway issueVirtualCard request'])
        ->toHaveKey('fundingModel', 'balance')
        ->not->toHaveKey('transactionReference');
});

/**
 * The cardholder's name is embossed on the card and is the same fact {@see CustomerIdentity} marks
 * as personal data. The command carries no `#[Pii]` attribute because it has no erasure
 * obligation, so the rule is applied to these two fields by what they are — and a name is not
 * needed to correlate an issuance.
 */
it('does not log the cardholder name', function () {
    $lines = issuedLines(IssueCardCommand::balanceFunded(
        gatewayId: GatewayId::generate(),
        amountLimit: new Money(5000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
        firstName: 'Ada',
        lastName: 'Lovelace',
    ));

    expect($lines['Gateway issueVirtualCard request'])
        ->not->toHaveKey('firstName')
        ->not->toHaveKey('lastName')
        ->and(json_encode($lines))->not->toContain('Lovelace');
});
