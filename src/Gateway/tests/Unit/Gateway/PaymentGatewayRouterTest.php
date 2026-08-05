<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Message\RequestInterface;
use Omnipay\Common\Message\ResponseInterface;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\Common\ValueObject\Email;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;
use Techork\PaymentService\Gateway\Contract\ConvertedAmountProvider;
use Techork\PaymentService\Gateway\Contract\Gateway as GatewayContract;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\Contract\GatewayTransactionRepository;
use Techork\PaymentService\Gateway\Contract\TransactionMetadataProvider;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\GatewayFactory;
use Techork\PaymentService\Gateway\PaymentGatewayRouter;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\Contract\CustomerReferenceProvider;

function makeRouterCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'Test'; }
        public function getCredentials(): array { return []; }
    };
}

function makeRouter(
    ?GatewayContract $omnipay = null,
    ?GatewayTransactionRepository $transactionRepo = null,
): PaymentGatewayRouter {
    $credential = makeRouterCredential();

    $credentialRepo = Mockery::mock(GatewayCredentialRepository::class);
    $credentialRepo->shouldReceive('findOrFail')->andReturn($credential);

    $factory = Mockery::mock(GatewayFactory::class);
    $factory->shouldReceive('createForCredential')->andReturn(
        $omnipay ?? Mockery::mock(GatewayContract::class)
    );

    $decrypter = Mockery::mock(DecryptInterface::class);
    $referenceRepo = Mockery::mock(GatewayInstrumentRepository::class);

    $txRepo = $transactionRepo ?? Mockery::mock(GatewayTransactionRepository::class);

    return new PaymentGatewayRouter(
        $factory,
        $decrypter,
        $credentialRepo,
        $referenceRepo,
        $txRepo,
    );
}

function makeSuccessResponse(string $ref): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn($ref);

    return $response;
}

function makeFailureResponse(string $message): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('isSuccessful')->andReturn(false);
    $response->shouldReceive('getMessage')->andReturn($message);

    return $response;
}

function makeOmnipayWithMethod(string $method, ResponseInterface $response): GatewayContract
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('send')->andReturn($response);

    $omnipay = Mockery::mock(GatewayContract::class);
    $omnipay->shouldReceive($method)->andReturn($request);

    return $omnipay;
}

// ──────────────────────────────────────────────
//  capture
// ──────────────────────────────────────────────

it('returns success on successful capture', function () {
    $gwId = GatewayId::generate();
    $piId = 'pi-' . uniqid();

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->with($piId)->andReturn('auth_ref');

    $omnipay = makeOmnipayWithMethod('capture', makeSuccessResponse('cap_123'));
    $gateway = makeRouter(omnipay: $omnipay, transactionRepo: $txRepo);

    $result = $gateway->capture($gwId, $piId, new Money(1000, new Currency('USD')));

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('cap_123');
});

it('returns failure on failed capture', function () {
    $gwId = GatewayId::generate();
    $piId = 'pi-' . uniqid();

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('auth_ref');

    $omnipay = makeOmnipayWithMethod('capture', makeFailureResponse('Capture declined'));
    $gateway = makeRouter(omnipay: $omnipay, transactionRepo: $txRepo);

    $result = $gateway->capture($gwId, $piId, new Money(1000, new Currency('USD')));

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Capture declined');
});

// ──────────────────────────────────────────────
//  refund
// ──────────────────────────────────────────────

it('returns success on successful refund', function () {
    $gwId = GatewayId::generate();
    $piId = 'pi-' . uniqid();

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('ch_ref');

    $omnipay = makeOmnipayWithMethod('refund', makeSuccessResponse('ref_456'));
    $gateway = makeRouter(omnipay: $omnipay, transactionRepo: $txRepo);

    $result = $gateway->refund($gwId, $piId, new Money(500, new Currency('USD')));

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('ref_456');
});

it('returns failure when gateway throws', function () {
    $gwId = GatewayId::generate();
    $piId = 'pi-' . uniqid();

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('ch_ref');

    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('send')->andThrow(new RuntimeException('Connection timeout'));

    $omnipay = Mockery::mock(GatewayContract::class);
    $omnipay->shouldReceive('refund')->andReturn($request);

    $gateway = makeRouter(omnipay: $omnipay, transactionRepo: $txRepo);

    $result = $gateway->refund($gwId, $piId, new Money(500, new Currency('USD')));

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Connection timeout');
});

// ──────────────────────────────────────────────
//  charge
// ──────────────────────────────────────────────

it('returns success on successful charge', function () {
    $omnipay = makeOmnipayWithMethod('purchase', makeSuccessResponse('ch_789'));
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')));

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('ch_789');
});

it('passes billing address through to charge', function () {
    $omnipay = makeOmnipayWithMethod('purchase', makeSuccessResponse('ch_billing'));
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);
    $billing = new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001', email: new Email('john@test.com'));

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')), billingAddress: $billing);

    expect($result->success)->toBeTrue();
});

// ──────────────────────────────────────────────
//  TransactionMetadataProvider extraction
// ──────────────────────────────────────────────

it('folds transaction metadata onto a successful capture result', function () {
    $gwId = GatewayId::generate();
    $piId = 'pi-' . uniqid();

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('auth_ref');

    $response = Mockery::mock(ResponseInterface::class, TransactionMetadataProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn('sale_guid');
    $response->shouldReceive('getTransactionMetadata')->andReturn(['incoming_transaction_code' => 'ICT-9']);

    $omnipay = makeOmnipayWithMethod('capture', $response);
    $gateway = makeRouter(omnipay: $omnipay, transactionRepo: $txRepo);

    $result = $gateway->capture($gwId, $piId, new Money(1000, new Currency('USD')));

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('sale_guid')
        ->and($result->metadata)->toBe(['incoming_transaction_code' => 'ICT-9']);
});

it('folds transaction metadata onto a successful charge result alongside checks', function () {
    $response = Mockery::mock(ResponseInterface::class, CardChecksProvider::class, TransactionMetadataProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn('sale_guid');
    $response->shouldReceive('getAddressLineCheck')->andReturn(CheckResult::Pass);
    $response->shouldReceive('getPostalCodeCheck')->andReturn(CheckResult::Pass);
    $response->shouldReceive('getCvcCheck')->andReturn(CheckResult::Pass);
    $response->shouldReceive('getTransactionMetadata')->andReturn(['incoming_transaction_code' => 'ICT-10']);

    $omnipay = makeOmnipayWithMethod('purchase', $response);
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')));

    expect($result->success)->toBeTrue()
        ->and($result->metadata)->toBe([
            'incoming_transaction_code' => 'ICT-10',
            'opening_transaction_reference' => 'sale_guid',
        ])
        ->and($result->cvcCheck)->toBe(CheckResult::Pass);
});

/**
 * Every opening operation records the reference it was given, because `reference`
 * cannot answer "which transaction opened this intent" later on — it overwrites on
 * transition, so once a capture lands the row holds the settle reference. The
 * rebilling anchor needs the earlier one: "not from the settle flow".
 *
 * Written here rather than by a port on purpose. Only opening operations pass through
 * buildAuthorization; capture, cancel and refund are built by buildOutcome, so no
 * later operation can write this key and bury the value with its own reference.
 */
it('records the opening reference even when the response carries no metadata of its own', function () {
    $omnipay = makeOmnipayWithMethod('purchase', makeSuccessResponse('ch_plain_meta'));
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')));

    expect($result->metadata)->toBe(['opening_transaction_reference' => 'ch_plain_meta']);
});

it('records no opening reference on a capture, so a settle cannot bury the anchor', function () {
    $txRepo = Mockery::mock(GatewayTransactionRepository::class);

    $response = Mockery::mock(ResponseInterface::class, TransactionMetadataProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn('settle_guid');
    $response->shouldReceive('getTransactionMetadata')->andReturn(['incoming_transaction_code' => 'ICT-11']);

    $router = makeRouter(omnipay: makeOmnipayWithMethod('capture', $response), transactionRepo: $txRepo);

    $result = $router->capture(GatewayId::generate(), 'auth_ref', new Money(100, new Currency('USD')));

    expect($result->metadata)->toBe(['incoming_transaction_code' => 'ICT-11'])
        ->and($result->metadata)->not->toHaveKey('opening_transaction_reference');
});

// ──────────────────────────────────────────────
//  ConvertedAmountProvider extraction
// ──────────────────────────────────────────────

it('folds the FX convertedAmount onto a successful charge result', function () {
    $converted = new Money(5712, new Currency('USD'));

    $response = Mockery::mock(ResponseInterface::class, ConvertedAmountProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn('ch_fx');
    $response->shouldReceive('getConvertedAmount')->andReturn($converted);

    $omnipay = makeOmnipayWithMethod('purchase', $response);
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(5000, new Currency('EUR')));

    expect($result->success)->toBeTrue()
        ->and($result->convertedAmount)->toBe($converted);
});

it('folds the FX convertedAmount onto a successful capture result', function () {
    $gwId = GatewayId::generate();
    $piId = 'pi-' . uniqid();
    $converted = new Money(9140, new Currency('USD'));

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('auth_ref');

    $response = Mockery::mock(ResponseInterface::class, ConvertedAmountProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn('cap_fx');
    $response->shouldReceive('getConvertedAmount')->andReturn($converted);

    $omnipay = makeOmnipayWithMethod('capture', $response);
    $gateway = makeRouter(omnipay: $omnipay, transactionRepo: $txRepo);

    $result = $gateway->capture($gwId, $piId, new Money(8000, new Currency('EUR')));

    expect($result->success)->toBeTrue()
        ->and($result->convertedAmount)->toBe($converted);
});

it('leaves convertedAmount null when the response does not report one', function () {
    $omnipay = makeOmnipayWithMethod('purchase', makeSuccessResponse('ch_plain_fx'));
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')));

    expect($result->convertedAmount)->toBeNull();
});

// ──────────────────────────────────────────────
//  CardChecksProvider extraction
// ──────────────────────────────────────────────

it('extracts card checks from a CardChecksProvider response on success', function () {
    $response = Mockery::mock(ResponseInterface::class, CardChecksProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn('ch_with_checks');
    $response->shouldReceive('getAddressLineCheck')->andReturn(CheckResult::Pass);
    $response->shouldReceive('getPostalCodeCheck')->andReturn(CheckResult::Fail);
    $response->shouldReceive('getCvcCheck')->andReturn(CheckResult::Pass);

    $omnipay = makeOmnipayWithMethod('purchase', $response);
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')));

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('ch_with_checks')
        ->and($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Fail)
        ->and($result->cvcCheck)->toBe(CheckResult::Pass)
        ->and($result->hasChecks())->toBeTrue();
});

it('leaves checks null when response does not implement CardChecksProvider', function () {
    $omnipay = makeOmnipayWithMethod('purchase', makeSuccessResponse('ch_plain'));
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')));

    expect($result->success)->toBeTrue()
        ->and($result->addressLineCheck)->toBeNull()
        ->and($result->postalCodeCheck)->toBeNull()
        ->and($result->cvcCheck)->toBeNull()
        ->and($result->hasChecks())->toBeFalse();
});

it('does not extract checks from a failed response', function () {
    $response = Mockery::mock(ResponseInterface::class, CardChecksProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(false);
    $response->shouldReceive('getMessage')->andReturn('Card declined');
    $response->shouldNotReceive('getAddressLineCheck');

    $omnipay = makeOmnipayWithMethod('purchase', $response);
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')));

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Card declined')
        ->and($result->hasChecks())->toBeFalse();
});

// ──────────────────────────────────────────────
//  updateVirtualCard
// ──────────────────────────────────────────────

it('returns the VirtualCardResult from a VirtualCardResponseInterface on update', function () {
    $expected = VirtualCardResult::succeeded(cardGuid: 'vc_guid_updated');

    $response = Mockery::mock(ResponseInterface::class, VirtualCardResponseInterface::class);
    $response->shouldReceive('toVirtualCardResult')->once()->andReturn($expected);

    $omnipay = makeOmnipayWithMethod('updateVirtualCard', $response);
    $gateway = makeRouter(omnipay: $omnipay);

    $result = $gateway->updateVirtualCard(
        GatewayId::generate(),
        'vc_guid_updated',
        new Money(2500, new Currency('USD')),
        CardSpendCategory::TravelGeneric,
    );

    expect($result)->toBe($expected);
});

it('falls back to plain succeeded when response is not VirtualCardResponseInterface', function () {
    $omnipay = makeOmnipayWithMethod('updateVirtualCard', makeSuccessResponse('vc_plain'));
    $gateway = makeRouter(omnipay: $omnipay);

    $result = $gateway->updateVirtualCard(
        GatewayId::generate(),
        'vc_input_guid',
        new Money(1000, new Currency('USD')),
        CardSpendCategory::TravelAir,
    );

    expect($result->success)->toBeTrue()
        ->and($result->cardGuid)->toBe('vc_plain');
});

it('returns failed VirtualCardResult when omnipay throws', function () {
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('send')->andThrow(new RuntimeException('upstream timeout'));

    $omnipay = Mockery::mock(GatewayContract::class);
    $omnipay->shouldReceive('updateVirtualCard')->andReturn($request);

    $gateway = makeRouter(omnipay: $omnipay);

    $result = $gateway->updateVirtualCard(
        GatewayId::generate(),
        'vc_guid_x',
        new Money(500, new Currency('USD')),
        CardSpendCategory::TravelAir,
    );

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('upstream timeout');
});

// ──────────────────────────────────────────────
//  invariant violations are not payment outcomes
// ──────────────────────────────────────────────

function makeHostedInstrument(): HostedPayment
{
    return new HostedPayment('https://shop.test/paid', 'https://shop.test/cancelled');
}

function makeOmnipayRefusing(string $method, string $operation): GatewayContract
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('send')->andThrow(
        UnsupportedInstrument::forGateway('test', $operation, makeHostedInstrument()),
    );

    $omnipay = Mockery::mock(GatewayContract::class);
    $omnipay->shouldReceive($method)->andReturn($request);

    return $omnipay;
}

it('rethrows an unsupported-instrument refusal from charge rather than reporting a decline', function () {
    $router = makeRouter(omnipay: makeOmnipayRefusing('purchase', 'purchase'));

    $router->charge(
        GatewayId::generate(),
        makeHostedInstrument(),
        new Money(1000, new Currency('USD')),
    );
})->throws(UnsupportedInstrument::class, 'does not accept a "hosted" instrument on the "purchase" operation');

it('rethrows an unsupported-instrument refusal from authorize', function () {
    $router = makeRouter(omnipay: makeOmnipayRefusing('authorize', 'authorize'));

    $router->authorize(
        GatewayId::generate(),
        makeHostedInstrument(),
        new Money(1000, new Currency('USD')),
    );
})->throws(UnsupportedInstrument::class, 'on the "authorize" operation');

it('rethrows an unsupported-instrument refusal from tokenize', function () {
    $router = makeRouter(omnipay: makeOmnipayRefusing('createCard', 'createCard'));

    $router->tokenize(GatewayId::generate(), makeHostedInstrument());
})->throws(UnsupportedInstrument::class, 'on the "createCard" operation');

it('rethrows an unsupported-instrument refusal from a plain outcome op', function () {
    $piId = 'pi-' . uniqid();
    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->with($piId)->andReturn('auth_ref');

    $router = makeRouter(omnipay: makeOmnipayRefusing('refund', 'retryRefund'), transactionRepo: $txRepo);

    $router->refund(GatewayId::generate(), $piId, new Money(1000, new Currency('USD')));
})->throws(UnsupportedInstrument::class, 'on the "retryRefund" operation');

// Every refusal above is thrown from `send()`. An issuing-only gateway refuses
// one step earlier — the omnipay METHOD itself throws, before there is a request
// to send — and that is the path Revolut's UnsupportedOperationException relies
// on. The builders wrap both calls in the same closure, so it must be rethrown
// just the same; if it were not, routing a refund to a gateway that never took
// the money would be recorded as RefundFailed, i.e. as an issuer decline.
it('rethrows a refusal thrown by the gateway method itself, not by send', function () {
    $piId = 'pi-'.uniqid();
    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->with($piId)->andReturn('auth_ref');

    $omnipay = Mockery::mock(GatewayContract::class);
    $omnipay->shouldReceive('refund')->andThrow(
        UnsupportedOperation::forGateway('test', 'refund', 'it is an issuing-only gateway'),
    );

    $router = makeRouter(omnipay: $omnipay, transactionRepo: $txRepo);

    $router->refund(GatewayId::generate(), $piId, new Money(1000, new Currency('USD')));
})->throws(UnsupportedOperation::class, 'does not support the "refund" operation');

it('still folds an ordinary gateway exception into a failed result', function () {
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('send')->andThrow(new RuntimeException('Connection timeout'));

    $omnipay = Mockery::mock(GatewayContract::class);
    $omnipay->shouldReceive('purchase')->andReturn($request);

    $result = makeRouter(omnipay: $omnipay)->charge(
        GatewayId::generate(),
        makeHostedInstrument(),
        new Money(1000, new Currency('USD')),
    );

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Connection timeout');
});

// ──────────────────────────────────────────────
//  the rebilling series reaches the request
//
//  The router is the only implementer of PaymentGatewayInterface, so a series fact
//  that does not make it into the parameter array here reaches no adapter at all.
//  These assert the keys rather than adapter behaviour: what each provider maps them
//  to is its own test's business.
// ──────────────────────────────────────────────

function captureOmnipayParams(string $method, ?array &$seen): GatewayContract
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('send')->andReturn(makeSuccessResponse('ref_1'));

    $omnipay = Mockery::mock(GatewayContract::class);
    $omnipay->shouldReceive($method)->andReturnUsing(function (array $params) use ($request, &$seen) {
        $seen = $params;

        return $request;
    });

    return $omnipay;
}

/** The router logs `$instrument->toPayload()`, so a bare mock is not enough. */
function initiationInstrument(): PaymentInstrument
{
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    return $instrument;
}

it('announces the series to the adapter, since Omnipay has no other way to say it', function () {
    $seen = null;
    $router = makeRouter(omnipay: captureOmnipayParams('authorize', $seen));

    $router->authorizeRebilling(
        GatewayId::generate(),
        initiationInstrument(),
        new Money(1000, new Currency('USD')),
        PaymentInitiation::MerchantRecurring,
        '1110000000123456',
    );

    // The flag IS the method choice crossing the parameter bag. Without it an
    // adapter cannot tell a series payment from an ordinary authorization, because
    // the other two fields do not separate them.
    expect($seen['rebilling'])->toBeTrue()
        ->and($seen['initiation'])->toBe(PaymentInitiation::MerchantRecurring)
        ->and($seen['rebillingReference'])->toBe('1110000000123456');
});

it('carries an absent genesis as absent, which inside a series means this payment opens it', function (PaymentInitiation $initiation) {
    $seen = null;
    $router = makeRouter(omnipay: captureOmnipayParams('authorize', $seen));

    $router->authorizeRebilling(
        GatewayId::generate(),
        initiationInstrument(),
        new Money(1000, new Currency('USD')),
        $initiation,
    );

    expect($seen['rebilling'])->toBeTrue()
        ->and($seen['rebillingReference'])->toBeNull()
        ->and($seen['initiation'])->toBe($initiation);
})->with([
    // Both open a series. Keying the position on CIT/MIT would get the second wrong.
    PaymentInitiation::CardholderInitiated,
    PaymentInitiation::MerchantUnscheduled,
]);

it('leaves an ordinary authorization with no series facts at all', function () {
    $seen = null;
    $router = makeRouter(omnipay: captureOmnipayParams('authorize', $seen));

    $router->authorize(
        GatewayId::generate(),
        initiationInstrument(),
        new Money(1000, new Currency('USD')),
    );

    // The initiation still travels — it is a fact about any payment, and Stripe's
    // off_session decision hangs on it. What an ordinary authorization has none of is
    // a POSITION: no series flag, no anchor.
    expect($seen['initiation'])->toBe(PaymentInitiation::CardholderInitiated)
        ->and($seen)->not->toHaveKey('rebilling')
        ->and($seen)->not->toHaveKey('rebillingReference');
});

it('leaves an ordinary charge with no series facts either', function () {
    $seen = null;
    $router = makeRouter(omnipay: captureOmnipayParams('purchase', $seen));

    $router->charge(
        GatewayId::generate(),
        initiationInstrument(),
        new Money(1000, new Currency('USD')),
    );

    expect($seen['initiation'])->toBe(PaymentInitiation::CardholderInitiated)
        ->and($seen)->not->toHaveKey('rebilling');
});

/**
 * A gateway that reports success without naming a transaction.
 *
 * `getMessage()` is stubbed with an approval so the assertions below cannot pass by
 * accidentally falling into the ordinary failure branch — the message they expect can only
 * come from the router refusing an unnamed success.
 */
function makeUnnamedSuccessResponse(?string $reference = null): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn($reference);
    $response->shouldReceive('getMessage')->andReturn('Approved');

    return $response;
}

it('refuses a success that names no transaction, on an outcome', function (?string $reference) {
    // Nothing could capture, cancel or refund it afterwards: the reference is the only
    // handle the ports get. This already failed before — as a TypeError caught by the same
    // handler, surfacing "must be of type string, null given" to the caller.
    $omnipay = makeOmnipayWithMethod('capture', makeUnnamedSuccessResponse($reference));
    $router = makeRouter(omnipay: $omnipay);

    $result = $router->capture(GatewayId::generate(), 'auth_ref', new Money(1000, new Currency('USD')));

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The gateway reported success without naming a transaction reference.');
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('refuses a success that names no transaction, on an authorization', function () {
    $omnipay = makeOmnipayWithMethod('purchase', makeUnnamedSuccessResponse());
    $router = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $router->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')));

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The gateway reported success without naming a transaction reference.');
});

// ──────────────────────────────────────────────
//  What the gateway is actually handed
// ──────────────────────────────────────────────
//
// Every mock above answers a method regardless of what it was called with, which
// is what let both of these calls lose their entire parameter array unnoticed. An
// omnipay request assembles itself from that array, so passing none is not a
// degraded call — it is a request that throws on `send()` for a missing parameter,
// gets caught, and is reported as the gateway refusing. These read the array.

/**
 * @param  array<string, mixed>|null  $seen
 */
function makeOmnipayCapturing(string $method, ResponseInterface $response, ?array &$seen): GatewayContract
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('send')->andReturn($response);

    $omnipay = Mockery::mock(GatewayContract::class);
    $omnipay->shouldReceive($method)->andReturnUsing(function (array $options = []) use ($request, &$seen) {
        $seen = $options;

        return $request;
    });

    return $omnipay;
}

it('hands the update the card, the limit and the category it was asked for', function () {
    $seen = null;
    $router = makeRouter(omnipay: makeOmnipayCapturing('updateVirtualCard', makeSuccessResponse('vc_updated'), $seen));

    $router->updateVirtualCard(
        GatewayId::generate(),
        'vc_guid_9',
        new Money(4200, new Currency('USD')),
        CardSpendCategory::TravelAir,
    );

    expect($seen)->toEqual([
        'transactionReference' => 'vc_guid_9',
        'money' => new Money(4200, new Currency('USD')),
        'spendCategory' => CardSpendCategory::TravelAir->value,
    ]);
});

it('hands a retry refund the alternative instrument and everything needed to reach it', function () {
    // The one operation whose refusal is expected to fold into a failed result, so a
    // call that could never have succeeded looks exactly like a gateway without the
    // primitive. Nothing downstream can tell the two apart — hence the assertion.
    $piId = 'pi-'.uniqid();
    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->with($piId)->andReturn('settle_ref');

    $failedStandard = Mockery::mock(ResponseInterface::class);
    $failedStandard->shouldReceive('isSuccessful')->andReturn(false);
    $failedStandard->shouldReceive('getMessage')->andReturn('Original card closed');

    $standardRequest = Mockery::mock(RequestInterface::class);
    $standardRequest->shouldReceive('send')->andReturn($failedStandard);

    $retryRequest = Mockery::mock(RequestInterface::class);
    $retryRequest->shouldReceive('send')->andReturn(makeSuccessResponse('credit_ref'));

    $seen = null;
    $omnipay = Mockery::mock(GatewayContract::class);
    $omnipay->shouldReceive('refund')->andReturn($standardRequest);
    $omnipay->shouldReceive('retryRefund')->andReturnUsing(function (array $options = []) use ($retryRequest, &$seen) {
        $seen = $options;

        return $retryRequest;
    });

    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = makeRouter(omnipay: $omnipay, transactionRepo: $txRepo)->refund(
        GatewayId::generate(),
        'settle_ref',
        new Money(1500, new Currency('USD')),
        "$piId:refund",
        $instrument,
    );

    expect($result->success)->toBeTrue()
        ->and($seen['money'])->toEqual(new Money(1500, new Currency('USD')))
        ->and($seen['transactionReference'])->toBe('settle_ref')
        ->and($seen['clientUniqueId'])->toBe("$piId:refund")
        ->and($seen['instrument'])->toBe($instrument)
        ->and($seen)->toHaveKeys(['gateway', 'decrypter', 'referenceResolver']);
});

// ──────────────────────────────────────────────
//  The four entry points nothing reached
//
//  cancel, createPaymentMethod, issueVirtualCard and terminateVirtualCard had no test
//  between them. Two of the three result builders were reachable only through these,
//  so buildRegistration and the virtual-card branch were unexecuted in full — including
//  the refusal of a success that names no transaction, which was added to all three
//  builders and pinned in only two.
// ──────────────────────────────────────────────

it('cancels through the gateway void, naming the transaction to void', function () {
    // `void` is what backs cancel, and the reference is the whole of what it needs. It used to be
    // looked up from the payment intent inside the router; now the caller supplies it, and a call
    // that dropped it would leave the gateway voiding nothing.
    $seen = null;
    $router = makeRouter(omnipay: makeOmnipayCapturing('void', makeSuccessResponse('void_1'), $seen));

    $result = $router->cancel(GatewayId::generate(), 'auth_ref_1', 'cuid-1');

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('void_1')
        ->and($seen['transactionReference'])->toBe('auth_ref_1')
        ->and($seen['clientUniqueId'])->toBe('cuid-1');
});

it('reports a refused cancellation with the gateway message', function () {
    $router = makeRouter(omnipay: makeOmnipayWithMethod('void', makeFailureResponse('Already settled')));

    $result = $router->cancel(GatewayId::generate(), 'auth_ref_1');

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Already settled');
});

it('refuses a cancellation the gateway called successful without naming it', function () {
    // The same rule as everywhere else: nothing could be reconciled against a void that names no
    // transaction, so reporting success would put an unverifiable cancellation in the stream.
    $router = makeRouter(omnipay: makeOmnipayWithMethod('void', makeUnnamedSuccessResponse()));

    expect($router->cancel(GatewayId::generate(), 'auth_ref_1')->message)
        ->toBe('The gateway reported success without naming a transaction reference.');
});

it('registers a payment method and hands the gateway everything the request needs', function () {
    // Six parameters, and the instrument, decrypter and reference resolver are what let the
    // provider build its request at all — a call that lost them would fail on send() and be
    // reported as the gateway refusing.
    $seen = null;
    $router = makeRouter(omnipay: makeOmnipayCapturing('createPaymentMethod', makeSuccessResponse('pm_1'), $seen));
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);
    $address = new BillingAddress('Ada', 'Lovelace', '1 Main St', 'London', new Country('GB'), 'E1 6AN');

    $result = $router->createPaymentMethod(GatewayId::generate(), $instrument, $address, 'cuid-2');

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('pm_1')
        ->and($seen['instrument'])->toBe($instrument)
        ->and($seen['billingAddress'])->toBe($address)
        ->and($seen['clientUniqueId'])->toBe('cuid-2')
        ->and($seen)->toHaveKeys(['gateway', 'decrypter', 'referenceResolver']);
});

it('carries the customer reference a registration response reports', function () {
    // The reason RegistrationResult has the field: a provider that creates a customer alongside the
    // instrument reports it here, and losing it means the next payment registers a second customer
    // for the same cardholder.
    $response = Mockery::mock(ResponseInterface::class, CustomerReferenceProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn('pm_2');
    $response->shouldReceive('getCustomerReference')->andReturn('cus_42');

    $router = makeRouter(omnipay: makeOmnipayWithMethod('createPaymentMethod', $response));
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    expect($router->createPaymentMethod(GatewayId::generate(), $instrument)->customerReference)->toBe('cus_42');
});

it('folds the AVS and CVC checks onto a registration result', function () {
    // These are the whole point of registering through the gateway rather than storing a card: the
    // issuer's verdict on the address and the security code arrives once, here, and nowhere else.
    $response = Mockery::mock(ResponseInterface::class, CardChecksProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn('pm_3');
    $response->shouldReceive('getAddressLineCheck')->andReturn(CheckResult::Pass);
    $response->shouldReceive('getPostalCodeCheck')->andReturn(CheckResult::Fail);
    $response->shouldReceive('getCvcCheck')->andReturn(CheckResult::Unchecked);

    $router = makeRouter(omnipay: makeOmnipayWithMethod('createPaymentMethod', $response));
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $router->createPaymentMethod(GatewayId::generate(), $instrument);

    expect($result->addressLineCheck)->toBe(CheckResult::Pass)
        ->and($result->postalCodeCheck)->toBe(CheckResult::Fail)
        ->and($result->cvcCheck)->toBe(CheckResult::Unchecked);
});

it('refuses a registration the gateway called successful without naming it', function () {
    // buildRegistration's copy of the rule, which was the one no test reached.
    $router = makeRouter(omnipay: makeOmnipayWithMethod('createPaymentMethod', makeUnnamedSuccessResponse()));
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $router->createPaymentMethod(GatewayId::generate(), $instrument);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The gateway reported success without naming a transaction reference.');
});

it('terminates a virtual card by its guid', function () {
    $seen = null;
    $router = makeRouter(omnipay: makeOmnipayCapturing('terminateVirtualCard', makeSuccessResponse('term_1'), $seen));

    $result = $router->terminateVirtualCard(GatewayId::generate(), 'card-guid-1');

    expect($result->success)->toBeTrue()
        // The guid travels as `transactionReference`, which is the provider requests' own name for
        // the thing being acted on — a rename on either side silently terminates nothing.
        ->and($seen['transactionReference'])->toBe('card-guid-1');
});

it('reports a refused termination with the gateway message', function () {
    $router = makeRouter(omnipay: makeOmnipayWithMethod('terminateVirtualCard', makeFailureResponse('Card already closed')));

    expect($router->terminateVirtualCard(GatewayId::generate(), 'card-guid-1')->message)->toBe('Card already closed');
});

it('refuses to issue a virtual card for a payment intent with no recorded transaction', function () {
    // A virtual card is funded by an authorization, so without its reference there is nothing to
    // fund the card from. This throws rather than answering a failed result: it is a caller error,
    // not a gateway refusal.
    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn(null);

    $router = makeRouter(omnipay: makeOmnipayWithMethod('issueVirtualCard', makeSuccessResponse('card_1')), transactionRepo: $txRepo);

    expect(fn () => $router->issueVirtualCard(
        GatewayId::generate(),
        'pi-missing',
        new Money(5000, new Currency('USD')),
        CardSpendCategory::TravelAir,
    ))->toThrow(RuntimeException::class, "Transaction reference for payment intent 'pi-missing' not found");
});

it('passes the stored authorization reference and incoming transaction code to the issuer', function () {
    // The metadata lookup exists to spare the gateway a Search/Sales round trip whose guid filters
    // ConnexPay silently ignores — so a call that dropped the code would still work and would
    // quietly cost a request per card.
    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('auth_ref_9');
    $txRepo->shouldReceive('findMetadataForPaymentIntent')->andReturn(['incoming_transaction_code' => 'ICT-77']);

    $seen = null;
    $router = makeRouter(
        omnipay: makeOmnipayCapturing('issueVirtualCard', makeSuccessResponse('card_1'), $seen),
        transactionRepo: $txRepo,
    );

    $result = $router->issueVirtualCard(
        GatewayId::generate(),
        'pi-1',
        new Money(5000, new Currency('USD')),
        CardSpendCategory::TravelAir,
        firstName: 'Ada',
        lastName: 'Lovelace',
        clientUniqueId: 'cuid-3',
    );

    expect($result->success)->toBeTrue()
        ->and($seen['transactionReference'])->toBe('auth_ref_9')
        ->and($seen['incomingTransactionCode'])->toBe('ICT-77')
        ->and($seen['spendCategory'])->toBe(CardSpendCategory::TravelAir->value)
        ->and($seen['firstName'])->toBe('Ada')
        ->and($seen['clientUniqueId'])->toBe('cuid-3');
});

it('prefers a provider virtual-card result over anything it could infer', function () {
    // A card carries a number, a CVV and an expiry that no generic result can hold, so a response
    // able to describe itself is asked to, and the router does not second-guess it.
    $card = VirtualCardResult::succeeded('card-guid-2');
    $response = Mockery::mock(ResponseInterface::class, VirtualCardResponseInterface::class);
    $response->shouldReceive('toVirtualCardResult')->andReturn($card);

    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('auth_ref_9');
    $txRepo->shouldReceive('findMetadataForPaymentIntent')->andReturn([]);

    $router = makeRouter(omnipay: makeOmnipayWithMethod('issueVirtualCard', $response), transactionRepo: $txRepo);

    expect($router->issueVirtualCard(
        GatewayId::generate(),
        'pi-1',
        new Money(5000, new Currency('USD')),
        CardSpendCategory::TravelAir,
    ))->toBe($card);
});

it('refuses a card the gateway called issued without naming it', function () {
    $txRepo = Mockery::mock(GatewayTransactionRepository::class);
    $txRepo->shouldReceive('findForPaymentIntent')->andReturn('auth_ref_9');
    $txRepo->shouldReceive('findMetadataForPaymentIntent')->andReturn([]);

    $router = makeRouter(omnipay: makeOmnipayWithMethod('issueVirtualCard', makeUnnamedSuccessResponse()), transactionRepo: $txRepo);

    $result = $router->issueVirtualCard(
        GatewayId::generate(),
        'pi-1',
        new Money(5000, new Currency('USD')),
        CardSpendCategory::TravelAir,
    );

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The gateway reported success without naming a transaction reference.');
});
