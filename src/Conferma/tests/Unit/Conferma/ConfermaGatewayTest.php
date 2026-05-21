<?php

declare(strict_types=1);

use Techork\PaymentService\Conferma\ConfermaGateway;
use Techork\PaymentService\Conferma\Exception\UnsupportedOperationException;
use Techork\PaymentService\Conferma\IssueVirtualCardRequest;
use Techork\PaymentService\Conferma\TerminateCardRequest;
use Techork\PaymentService\Conferma\UpdateVirtualCardRequest;

function makeConfermaGateway(): ConfermaGateway
{
    $gw = new ConfermaGateway;
    $gw->initialize([
        'clientId' => 'cid',
        'clientSecret' => 'csec',
        'platformKey' => 'pk-xyz',
        'accessToken' => 'preset-bearer',
        'environment' => 'sandbox',
        'clientAccountType' => 'CLIENTID',
        'clientAccountValue' => '1234',
        'defaultSupplierName' => 'My Supplier',
        'paymentRangeDays' => 14,
    ]);

    return $gw;
}

it('has name Conferma', function () {
    expect(makeConfermaGateway()->getName())->toBe('conferma');
});

it('initializes parameters', function () {
    $gw = makeConfermaGateway();

    expect($gw->getClientId())->toBe('cid')
        ->and($gw->getClientSecret())->toBe('csec')
        ->and($gw->getPlatformKey())->toBe('pk-xyz')
        ->and($gw->getAccessToken())->toBe('preset-bearer')
        ->and($gw->getEnvironment())->toBe('sandbox')
        ->and($gw->getClientAccountType())->toBe('CLIENTID')
        ->and($gw->getClientAccountValue())->toBe('1234')
        ->and($gw->getDefaultSupplierName())->toBe('My Supplier')
        ->and($gw->getPaymentRangeDays())->toBe(14);
});

it('creates issueVirtualCard request', function () {
    expect(makeConfermaGateway()->issueVirtualCard())->toBeInstanceOf(IssueVirtualCardRequest::class);
});

it('creates updateVirtualCard request', function () {
    expect(makeConfermaGateway()->updateVirtualCard())->toBeInstanceOf(UpdateVirtualCardRequest::class);
});

it('creates terminateVirtualCard request', function () {
    expect(makeConfermaGateway()->terminateVirtualCard())->toBeInstanceOf(TerminateCardRequest::class);
});

it('throws UnsupportedOperationException on acquiring methods', function (string $method) {
    expect(fn () => makeConfermaGateway()->{$method}())
        ->toThrow(UnsupportedOperationException::class);
})->with([
    'purchase',
    'authorize',
    'capture',
    'refund',
    'void',
    'createCard',
    'createPaymentMethod',
]);

it('forwards gateway-level config into the constructed VC request', function () {
    /** @var IssueVirtualCardRequest $request */
    $request = makeConfermaGateway()->issueVirtualCard();

    expect($request->getClientAccountType())->toBe('CLIENTID')
        ->and($request->getClientAccountValue())->toBe('1234')
        ->and($request->getDefaultSupplierName())->toBe('My Supplier')
        ->and($request->getPaymentRangeDays())->toBe(14);
});
