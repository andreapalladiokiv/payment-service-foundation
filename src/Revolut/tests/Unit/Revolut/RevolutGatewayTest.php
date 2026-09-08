<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Revolut\Exception\UnsupportedOperationException;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Common\Contract\CustomerIdentifier;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;

/**
 * Capture takes a typed command now, so the datasets below cannot call it bare. The helper keeps
 * the refusal sets intact — what they pin is the refusal, not the signature — and shrinks as the
 * remaining operations move onto roles of their own.
 */
function revolutInvoke(Techork\PaymentService\Revolut\RevolutGateway $gateway, string $operation): mixed
{
    return match ($operation) {
        'capture' => $gateway->capture(new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'ref',
            amount: new Money(100, new Currency('USD')),
        )),
        'cancel' => $gateway->cancel(new CancelCommand(GatewayId::generate(), 'ref')),
        'charge', 'authorize' => $gateway->{$operation}(new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: Mockery::mock(PaymentInstrument::class),
            amount: new Money(100, new Currency('USD')),
        )),
        'refund', 'retryRefund' => $gateway->{$operation}(new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'ref',
            amount: new Money(100, new Currency('USD')),
        )),
        'tokenize', 'registerPaymentMethod' => $gateway->{$operation}(new VaultCommand(
            gatewayId: GatewayId::generate(),
            instrument: Mockery::mock(PaymentInstrument::class),
        )),
        'registerCustomer' => $gateway->registerCustomer(new RegisterCustomerCommand(
            gatewayId: GatewayId::generate(),
            customerId: revolutTestCustomerId(),
            identity: new CustomerIdentity('Ada', 'Lovelace'),
        )),
        'issueVirtualCard' => $gateway->issueVirtualCard(new IssueCardCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'sale-guid',
            amountLimit: new Money(100, new Currency('USD')),
            spendCategory: CardSpendCategory::TravelAir,
        )),
        'updateVirtualCard' => $gateway->updateVirtualCard(new UpdateCardCommand(
            GatewayId::generate(),
            'card-guid',
            new Money(100, new Currency('USD')),
            CardSpendCategory::TravelAir,
        )),
        'terminateVirtualCard' => $gateway->terminateVirtualCard(
            new TerminateCardCommand(GatewayId::generate(), 'card-guid'),
        ),
        default => $gateway->{$operation}(),
    };
}

/**
 * The body the gateway actually sent. There used to be an `issuing()` accessor handing back the
 * operation so a test could call `payload()` on it without issuing anything; the gateway issues,
 * so the transport is the seam. That is also the more honest reading here, because what these
 * tests are about is the gateway's own configuration — `accountIds`, `product`, `validityDays` —
 * reaching the card, which only the round trip through `configure()` and the operation shows.
 *
 * @param  array<string, mixed>  $params  gateway configuration
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function revolutCardPayload(array $params, string $operation, array $options = []): array
{
    $client = new class implements RevolutHttpClientInterface
    {
        /** @var array<string, mixed> */
        public array $body = [];

        public function post(string $path, array $data = []): array
        {
            $this->body = $data;

            return ['id' => 'card-guid', 'state' => 'active'];
        }

        public function patch(string $path, array $data): array
        {
            $this->body = $data;

            return ['id' => 'card-guid', 'state' => 'active'];
        }

        public function get(string $path): array
        {
            return [];
        }

        public function delete(string $path): array
        {
            return [];
        }
    };

    $gateway = makeRevolutGateway($client, $params);

    $operation === 'issue'
        ? $gateway->issueVirtualCard(new IssueCardCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: $options['transactionReference'] ?? 'sale-guid',
            amountLimit: $options['money'] ?? new Money(1000, new Currency('USD')),
            spendCategory: $options['spendCategory'] ?? CardSpendCategory::TravelAir,
            clientUniqueId: $options['clientUniqueId'] ?? null,
        ))
        : $gateway->updateVirtualCard(new UpdateCardCommand(
            GatewayId::generate(),
            $options['cardGuid'] ?? 'card-guid',
            $options['money'] ?? new Money(1000, new Currency('USD')),
            $options['spendCategory'] ?? CardSpendCategory::TravelAir,
        ));

    return $client->body;
}

it('has name revolut', function () {
    expect(makeRevolutGateway()->getName())->toBe('revolut');
});

/*
 * Three tests asserting that the gateway returns an `IssueVirtualCardRequest`, an
 * `UpdateVirtualCardRequest` and a `TerminateCardRequest` lived here. Those classes are gone: an
 * operation is not a request object waiting to be sent, so what is worth pinning is the payload it
 * builds and the result it maps, both of which have their own files. What the gateway hands back
 * is checked by the type system.
 */

it('throws on every acquiring / tokenization operation', function (string $operation) {
    revolutInvoke(makeRevolutGateway(), $operation);
})->throws(UnsupportedOperationException::class)->with([
    'charge',
    'authorize',
    'capture',
    'refund',
    'cancel',
    'tokenize',
    'registerPaymentMethod',
    // Revolut has no customer object at all, so this is the same absence as the rest rather than
    // a capability gap — unlike ConnexPay, which has one and no way to create it from an identity.
    'registerCustomer',
]);

// The class alone is not the guarantee. Without the marker interface the router
// folds this into a failed result and the stream records PaymentIntentFailed /
// RefundFailed — i.e. it claims an issuer declined a payment that was never sent.
// Revolut acquires nothing, so every one of these is a misrouting, refund
// included: there is no retryRefund primitive here to degrade gracefully.
it('refuses acquiring operations as a wiring error, not as an acquirer decline', function () {
    expect(is_subclass_of(UnsupportedOperationException::class, UnsupportedByGateway::class))->toBeTrue();
});

it('throws something the router will rethrow rather than swallow', function (string $operation) {
    try {
        revolutInvoke(makeRevolutGateway(), $operation);
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(UnsupportedByGateway::class);

        return;
    }

    $this->fail("Revolut::{$operation}() did not throw at all.");
})->with([
    'charge',
    'authorize',
    'capture',
    'refund',
    'cancel',
    'tokenize',
    'registerPaymentMethod',
]);

it('always resolves to the production host (Revolut has no card sandbox)', function () {
    expect(makeRevolutGateway()->getResolvedBaseUrl())->toBe('https://b2b.revolut.com');
});

it('lets an explicit base URL override the production default', function () {
    expect(makeRevolutGateway(params: ['baseUrl' => 'https://proxy.internal'])->getResolvedBaseUrl())
        ->toBe('https://proxy.internal');
});

it('injects gateway-level card configuration into issued cards', function () {
    $account = '11111111-1111-1111-1111-111111111111';

    $data = revolutCardPayload([
        'accountIds' => [$account],
        'product' => 'prod_gw',
        'spendLimitPeriod' => 'month',
        'validityDays' => 14,
        'fetchSensitiveDetails' => false,
    ], 'issue', [
        'money' => new Money(5000, new Currency('GBP')),
        'clientUniqueId' => 'req-1',
    ]);

    expect($data['accounts'])->toBe([$account])
        ->and($data['product'])->toBe(['code' => 'prod_gw'])
        ->and($data['spending_limits'])->toBe(['month' => ['amount' => 50.00, 'currency' => 'GBP']])
        ->and($data['spending_period']['end_date_action'])->toBe('terminate');

    // `fetchSensitiveDetails` shapes what the request does after the card exists, not the body it
    // sends, so it is asserted where that behaviour lives — IssueVirtualCardRequestTest.
});

it('tolerates a legacy single-string account id', function () {
    $account = '11111111-1111-1111-1111-111111111111';

    $data = revolutCardPayload(['accountIds' => $account], 'issue', [
        'money' => new Money(5000, new Currency('GBP')),
    ]);

    expect($data['accounts'])->toBe([$account]);
});

it('drops non-uuid account ids from the allow-list', function () {
    $account = '11111111-1111-1111-1111-111111111111';

    expect(revolutCardPayload(['accountIds' => ['not-a-uuid', $account]], 'issue', ['money' => new Money(5000, new Currency('GBP'))])['accounts'])->toBe([$account])
        ->and(revolutCardPayload(['accountIds' => ['not-a-uuid']], 'issue', ['money' => new Money(5000, new Currency('GBP'))]))->not->toHaveKey('accounts');
});

/**
 * A customer id the adapter can hold without being able to make one: Revolut depends on `Common` and
 * `Gateway`, never on the domain, which is the property
 * {@see \Techork\PaymentService\Common\Contract\CustomerIdentifier} exists to give.
 */
function revolutTestCustomerId(): CustomerIdentifier
{
    static $id = null;

    return $id ??= new readonly class implements CustomerIdentifier
    {
        public function toString(): string
        {
            return '01920000-0000-7000-8000-00000000cafe';
        }

        public function __toString(): string
        {
            return $this->toString();
        }
    };
}
