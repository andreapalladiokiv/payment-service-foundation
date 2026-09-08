<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\ConnexPay\ConnexPayGateway;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Behaviour the conversion deliberately did NOT change, pinned so that changing it later has to be
 * a decision rather than a side effect.
 *
 * The Purchases API operations compare a card limit against USD whatever the merchant account is
 * provisioned in. Before the conversion the two card methods reached their requests through
 * Omnipay's `AbstractGateway::createRequest()` directly, skipping this driver's own override — and
 * that override was the only thing putting `accountCurrency` into the parameter bag, because
 * `configure()` reads the credential into a typed property and never calls `setParameter()`.
 * Verified by running the pre-conversion code against a GBP account: a GBP card limit threw
 * "provisioned in USD but the amount is GBP", exactly as it does here.
 *
 * The acquiring side is unaffected and is asserted alongside, because the two readings differing
 * is the whole oddity: one account, two answers about its currency.
 */
function cardCurrencyGateway(): ConnexPayGateway
{
    $gateway = new ConnexPayGateway;
    $gateway->configure(new Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure(
        cpCredential(),
        cpDecrypter(),
        cpInstruments(null),
        Mockery::mock(Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository::class, ['find' => null]),
        ['username' => 'u', 'password' => 'p', 'merchantGuid' => 'm-1', 'account_currency' => 'GBP'],
    ));
    $gateway->setHttpClient(cpHttpClient());

    return $gateway;
}

it('still measures a card limit against USD on a GBP account', function () {
    cardCurrencyGateway()->issueVirtualCard(new IssueCardCommand(
        gatewayId: GatewayId::generate(),
        transactionReference: 'sale-guid',
        amountLimit: new Money(5000, new Currency('GBP')),
        spendCategory: CardSpendCategory::TravelAir,
        incomingTransactionCode: 'ICT-1',
    ));
})->throws(InvalidArgumentException::class, 'provisioned in USD but the amount is GBP');

it('still measures a card update against USD on a GBP account', function () {
    cardCurrencyGateway()->updateVirtualCard(new UpdateCardCommand(
        GatewayId::generate(),
        'card-guid',
        new Money(2500, new Currency('GBP')),
        CardSpendCategory::TravelAir,
    ));
})->throws(InvalidArgumentException::class, 'provisioned in USD but the amount is GBP');

it('measures an acquiring amount against the configured currency, as it always did', function () {
    expect(cardCurrencyGateway()->settings()->acquiringCurrency())->toBe('GBP');
});
