<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\ValueObject\BalanceFunded;
use Techork\PaymentService\Gateway\ValueObject\CardFundingModel;
use Techork\PaymentService\Gateway\ValueObject\CardLimitWindow;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\SaleFunded;
use Techork\PaymentService\Gateway\ValueObject\SaleFundingHint;

/**
 * The funding pair, and what it is allowed to know.
 *
 * There is no test here for "a balance card refuses to name a transaction reference", and its
 * absence is the point: {@see BalanceFunded} has no such property, so the state is unrepresentable
 * rather than rejected. A test asserting an exception would be evidence that the type had failed
 * to carry the invariant — which is exactly what the flat nullable object this replaced needed.
 */
it('names the sale on the sale case and nothing at all on the balance case', function () {
    expect(new SaleFunded('sale-guid')->transactionReference)->toBe('sale-guid')
        ->and(new SaleFunded('sale-guid')->model())->toBe(CardFundingModel::Sale)
        ->and(new BalanceFunded()->model())->toBe(CardFundingModel::Balance)
        // The whole public surface of the balance case, asserted as such: anything a balance card
        // additionally needs belongs to one vendor and travels on that vendor's own channel.
        ->and(get_object_vars(new BalanceFunded))->toBe([]);
});

it('carries a gateway hint opaquely and names it only by type in the log', function () {
    $hint = new class implements SaleFundingHint {};

    $funding = new SaleFunded('sale-guid', $hint);

    expect($funding->hint)->toBe($hint)
        ->and($funding->toLogContext())
        ->toHaveKey('transactionReference', 'sale-guid')
        ->toHaveKey('fundingModel', 'sale')
        ->toHaveKey('fundingHint', $hint::class)
        // Whatever is inside a hint belongs to one gateway; a log line every gateway writes is
        // the wrong place to spell it out.
        ->and(json_encode($funding->toLogContext()))->not->toContain('value');
});

it('says which funding model a command was built for', function () {
    $sale = IssueCardCommand::saleFunded(
        gatewayId: GatewayId::generate(),
        transactionReference: 'sale-guid',
        amountLimit: new Money(5000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
    );

    $balance = IssueCardCommand::balanceFunded(
        gatewayId: GatewayId::generate(),
        amountLimit: new Money(5000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
    );

    expect($sale->funding)->toBeInstanceOf(SaleFunded::class)
        ->and($balance->funding)->toBeInstanceOf(BalanceFunded::class)
        ->and($balance->toLogContext())
        ->toHaveKey('fundingModel', 'balance')
        // Not merely null — absent. A balance card has no reference for a reader to find empty.
        ->not->toHaveKey('transactionReference');
});

/**
 * The window is a spend control the caller asks for, so it sits beside the spend category on the
 * command rather than inside the balance case — which is also what lets an update carry one, since
 * an update names no funding model at all.
 */
it('carries the limit window on the command for either funding model', function () {
    $command = IssueCardCommand::balanceFunded(
        gatewayId: GatewayId::generate(),
        amountLimit: new Money(5000, new Currency('USD')),
        spendCategory: CardSpendCategory::TravelAir,
        limitWindow: CardLimitWindow::Month,
    );

    expect($command->limitWindow)->toBe(CardLimitWindow::Month)
        ->and($command->toLogContext())->toHaveKey('limitWindow', 'month');
});

/**
 * The shared vocabulary is the intersection of what the two supported issuers honour natively.
 * Revolut's `single` / `quarter` / `year` are deliberately absent: admitting them would leave
 * ConnexPay's mapper choosing a closest fit, and a mis-fitted window changes how much the card may
 * spend rather than merely what it is called.
 */
it('admits only the periods both issuers honour', function () {
    expect(array_column(CardLimitWindow::cases(), 'value'))
        ->toBe(['day', 'week', 'month', 'lifetime']);
});
