<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\ConnexPay\Authorize;
use Techork\PaymentService\ConnexPay\Purchase;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * `opening_transaction_reference` is written by the operations that OPEN a payment intent, and by
 * no others.
 *
 * It matters because `reference` cannot answer the question later — it overwrites on transition,
 * so once a capture lands the row holds the settle reference —  and because
 * {@see \Techork\PaymentService\Laravel\Port\RebillingCreateAdapter} reads the key back to anchor
 * a stored-credential series. Losing it on the opening operations silently breaks subscriptions;
 * writing it on a settling operation buries the anchor under the transaction that settled it.
 *
 * Before the conversion the split came from the shared folder: `ResultAssembler::authorization()`
 * added the key and `ResultAssembler::outcome()` did not, so it landed on charge, authorize and
 * authorizeRebilling and nowhere else. These pin the same split now that each operation maps its
 * own answer.
 *
 * @param  array<string, mixed>  $response
 */
function openingClient(array $response): Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface
{
    $client = cpHttpClient();
    $client->shouldReceive('post')->andReturn($response);

    return $client;
}

it('records the opening reference on a charge', function () {
    $result = new Purchase(
        cpSettings(),
        cpPlacement(['instrument' => cpCard()]),
        cpInfrastructure(),
        openingClient(['wasProcessed' => true, 'guid' => 'sale-guid']),
    )->charge();

    expect($result->metadata)->toHaveKey('opening_transaction_reference', 'sale-guid');
});

it('records the opening reference on an authorization', function () {
    $result = new Authorize(
        cpSettings(),
        cpPlacement(['instrument' => cpCard()]),
        cpInfrastructure(),
        openingClient(['wasProcessed' => true, 'guid' => 'auth-guid']),
    )->authorize();

    expect($result->metadata)->toHaveKey('opening_transaction_reference', 'auth-guid');
});

/**
 * The one that breaks subscriptions if it goes missing: the anchor a later renewal is chained to
 * is written by the payment that opened the series.
 */
it('records the opening reference on a rebilling authorization', function () {
    $result = new Authorize(
        cpSettings(),
        new RebillingCommand(
            gatewayId: GatewayId::generate(),
            instrument: cpCard(),
            amount: new Money(1000, new Currency('USD')),
            initiation: PaymentInitiation::CardholderInitiated,
        ),
        cpInfrastructure(),
        openingClient(['wasProcessed' => true, 'guid' => 'genesis-guid']),
    )->authorize();

    expect($result->metadata)->toHaveKey('opening_transaction_reference', 'genesis-guid');
});

/**
 * A hosted payment opens the intent too — it parks in `RequiresAction` until the webhook lands —
 * so it names itself the opening transaction under the only reference it has, our OrderNumber.
 */
it('records the opening reference on the hosted path', function () {
    $result = hostedCpPurchase(wiring: ['client' => openingClient([
        'tempToken' => 'tok-1',
        'otherUrl' => 'https://pay.cxppayments.com/HostedPaymentResult',
    ])])->charge();

    expect($result->metadata)->toHaveKey('opening_transaction_reference', HPP_PI_ID);
});

it('does not record it on the settling and reversing operations', function (callable $run) {
    expect($run()->metadata)->not->toHaveKey('opening_transaction_reference');
})->with([
    'capture' => [fn () => cpCapture(client: openingClient(['sale' => ['wasProcessed' => true, 'guid' => 'g']]))->capture()],
    'void' => [fn () => cpVoid(client: openingClient(['wasProcessed' => true, 'guid' => 'g']))->cancel()],
    'refund' => [fn () => cpRefund(client: openingClient(['wasProcessed' => true, 'guid' => 'g']))->refund()],
    'retry refund' => [fn () => cpReturnRetry(cpCard(), wiring: ['client' => openingClient(['wasProcessed' => true, 'guid' => 'g'])])->retry()],
    'partial capture' => [fn () => cpPartialCapture(openingClient(['wasProcessed' => true, 'guid' => 'g']))->capture()],
]);
