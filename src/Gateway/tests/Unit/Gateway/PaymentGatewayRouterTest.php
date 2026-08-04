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
use Techork\PaymentService\Gateway\Contract\StoredCredentialReferenceProvider;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Contract\TransactionMetadataProvider;
use Techork\PaymentService\Gateway\Contract\VirtualCardResponseInterface;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\GatewayFactory;
use Techork\PaymentService\Gateway\PaymentGatewayRouter;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;

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
        ->and($result->metadata)->toBe(['incoming_transaction_code' => 'ICT-10'])
        ->and($result->cvcCheck)->toBe(CheckResult::Pass);
});

it('leaves metadata empty when the response carries none', function () {
    $omnipay = makeOmnipayWithMethod('purchase', makeSuccessResponse('ch_plain_meta'));
    $gateway = makeRouter(omnipay: $omnipay);
    $instrument = Mockery::mock(PaymentInstrument::class);
    $instrument->shouldReceive('toPayload')->andReturn([]);

    $result = $gateway->charge(GatewayId::generate(), $instrument, new Money(100, new Currency('USD')));

    expect($result->metadata)->toBe([]);
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
//  the stored-credential indicator reaches the request
//
//  The router is the only implementer of PaymentGatewayInterface, so if the
//  initiation does not make it into the parameter array here it reaches no
//  adapter at all — which is what happened for as long as CIT/MIT existed only
//  in the domain. Asserting the key rather than adapter behaviour keeps this a
//  test of the seam: what each provider maps it to is its own test's business.
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

it('puts the initiation in the parameters it hands the charge request', function (PaymentInitiation $initiation) {
    $seen = null;
    $router = makeRouter(omnipay: captureOmnipayParams('purchase', $seen));

    $router->charge(
        GatewayId::generate(),
        initiationInstrument(),
        new Money(1000, new Currency('USD')),
        initiation: $initiation,
    );

    expect($seen)->toHaveKey('initiation')
        ->and($seen['initiation'])->toBe($initiation);
})->with([
    PaymentInitiation::CardholderInitiated,
    PaymentInitiation::MerchantRecurring,
    PaymentInitiation::MerchantUnscheduled,
]);

it('puts the initiation in the parameters it hands the authorize request', function (PaymentInitiation $initiation) {
    $seen = null;
    $router = makeRouter(omnipay: captureOmnipayParams('authorize', $seen));

    $router->authorize(
        GatewayId::generate(),
        initiationInstrument(),
        new Money(1000, new Currency('USD')),
        initiation: $initiation,
    );

    expect($seen)->toHaveKey('initiation')
        ->and($seen['initiation'])->toBe($initiation);
})->with([
    PaymentInitiation::CardholderInitiated,
    PaymentInitiation::MerchantRecurring,
    PaymentInitiation::MerchantUnscheduled,
]);

it('defaults the initiation to cardholder-initiated when a caller omits it', function () {
    $seen = null;
    $router = makeRouter(omnipay: captureOmnipayParams('purchase', $seen));

    $router->charge(
        GatewayId::generate(),
        initiationInstrument(),
        new Money(1000, new Currency('USD')),
    );

    expect($seen['initiation'])->toBe(PaymentInitiation::CardholderInitiated);
});

// ──────────────────────────────────────────────
//  the credential-establishing transaction survives the registration
// ──────────────────────────────────────────────

function makeRegistrationResponse(string $reference, ?string $storedCredentialReference): ResponseInterface
{
    if ($storedCredentialReference === null) {
        // A response that began no chain does not implement the interface at all,
        // which is the case the null branch has to cope with.
        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('isSuccessful')->andReturn(true);
        $response->shouldReceive('getTransactionReference')->andReturn($reference);

        return $response;
    }

    $response = Mockery::mock(ResponseInterface::class, StoredCredentialReferenceProvider::class);
    $response->shouldReceive('isSuccessful')->andReturn(true);
    $response->shouldReceive('getTransactionReference')->andReturn($reference);
    $response->shouldReceive('getStoredCredentialReference')->andReturn($storedCredentialReference);

    return $response;
}

it('carries the credential-establishing transaction onto the registration result', function () {
    $router = makeRouter(omnipay: makeOmnipayWithMethod(
        'createPaymentMethod',
        makeRegistrationResponse('upo_9001', '1110000000123456'),
    ));

    $result = $router->createPaymentMethod(
        GatewayId::generate(),
        initiationInstrument(),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    expect($result->storedCredentialReference)->toBe('1110000000123456')
        // The instrument reference is a different value and must not be displaced.
        ->and($result->reference)->toBe('upo_9001');
});

it('leaves the anchor null when the registration began no chain', function () {
    $router = makeRouter(omnipay: makeOmnipayWithMethod(
        'createPaymentMethod',
        makeRegistrationResponse('upo_4242', null),
    ));

    $result = $router->createPaymentMethod(
        GatewayId::generate(),
        initiationInstrument(),
        new BillingAddress('Test', 'User', '1 St', 'NYC', new Country('US'), '10001'),
    );

    expect($result->storedCredentialReference)->toBeNull()
        ->and($result->reference)->toBe('upo_4242');
});

it('keeps the anchor when the checks wither rebuilds the result', function () {
    // withChecks() and withCustomerReference() both reconstruct the whole readonly
    // object, so a field they forget to copy is silently lost on the way through
    // buildRegistration — which attaches all three.
    $result = RegistrationResult::succeeded('upo_1')
        ->withStoredCredentialReference('txn_1')
        ->withCustomerReference('cust_1')
        ->withChecks(CheckResult::Pass, CheckResult::Pass, CheckResult::Pass);

    expect($result->storedCredentialReference)->toBe('txn_1')
        ->and($result->customerReference)->toBe('cust_1');
});
