<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Gateway\Exception\IncompleteAuthentication;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;

// ──────────────────────────────────────────────
//  CreditCard — ThreeDS in Card
// ──────────────────────────────────────────────

it('includes ThreeDS in Card when threeDS present', function () {
    $data = cpAuthorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => cpCard(),
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::Successful,
            'cavv-auth-value',
            ECICode::MastercardSuccessful,
            'ds-txn-auth',
            'acs-txn-auth',
            ThreeDSVersion::V220,
        ),
    ])->payload();

    expect($data['Card']['ThreeDS'])->toBe([
        'Cavv' => 'cavv-auth-value',
        'Version' => '2.2.0',
        'DirectoryServerTransactionID' => 'ds-txn-auth',
        'AcsTransactionId' => 'acs-txn-auth',
        'ECI' => '02',
    ]);
});

// ──────────────────────────────────────────────
//  CreditCard — no ThreeDS when null
// ──────────────────────────────────────────────

it('excludes ThreeDS when threeDS is null', function () {
    $data = cpAuthorize(['money' => new Money(5000, new Currency('USD')), 'instrument' => cpCard()])->payload();

    expect($data['Card'])->not->toHaveKey('ThreeDS');
});

// ──────────────────────────────────────────────
//  Cash — routed to purchase by the gateway; Authorize refuses
// ──────────────────────────────────────────────

it('refuses Cash with ThreeDS (Cash must go through purchase)', function () {
    cpAuthorize([
        'money' => new Money(3000, new Currency('USD')),
        'instrument' => new Cash,
        'threeDS' => cpThreeDS('cavv-ignored', ECICode::VisaSuccessful),
    ])->payload();
})->throws(UnsupportedInstrument::class, 'does not accept a "cash" instrument on the "authorize" operation');

// ──────────────────────────────────────────────
//  Registration — /api/v1/verify also carries ThreeDS
//
//  Reachable only by handing the operation an attestation: {@see \Techork\PaymentService\Gateway\Command\VaultCommand}
//  has no field for one, so today the gateway supplies none. The wire behaviour is real
//  regardless — without forwarding it the step-up is performed and then discarded, and the
//  issuer sees an unauthenticated verification.
// ──────────────────────────────────────────────

it('forwards ThreeDS when registering a payment method', function () {
    $data = cpRegister(['instrument' => cpCard()], ['threeDS' => cpThreeDS('cavv-registration')])->payload();

    expect($data['Card']['ThreeDS']['Cavv'])->toBe('cavv-registration')
        ->and($data['Card']['ThreeDS']['ECI'])->toBe('02');
});

it('omits ThreeDS from a registration that was not authenticated', function () {
    expect(cpRegister(['instrument' => cpCard()])->payload()['Card'])->not->toHaveKey('ThreeDS');
});

it('forwards ThreeDS when tokenizing a card', function () {
    $data = cpCreateCard(['instrument' => cpCard()], ['threeDS' => cpThreeDS('cavv-tokenize')])->payload();

    expect($data['Card']['ThreeDS']['Cavv'])->toBe('cavv-tokenize');
});

// ──────────────────────────────────────────────
//  A cryptogram-less attestation is refused, not downgraded
//
//  Measured against the sandbox (ConnexPayThreeDSFieldProbeTest, 2026-08-04):
//  ConnexPay accepts a ThreeDS block whose Cavv is null and returns
//  type: "Default" — the same value as a request carrying no ThreeDS block at
//  all — where a Cavv-bearing request returns type: "Secured3D". So the block is
//  not rejected, it is processed as unauthenticated, silently. ECI is a
//  different case: with ECI null and a Cavv present the sale still comes back
//  Secured3D, so ECI is deliberately NOT required here even though Nuvei
//  requires it.
// ──────────────────────────────────────────────

it('refuses an attestation with no Cavv rather than being silently downgraded to Default', function () {
    cpAuthorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => cpCard(),
        // NotAuthenticated carries no authentication value — the shape an app
        // produces when it forwards a failed authentication as evidence.
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::NotAuthenticated,
            null,
            ECICode::MastercardFailed,
            'ds-txn-auth',
            'acs-txn-auth',
            ThreeDSVersion::V220,
        ),
    ])->payload();
})->throws(IncompleteAuthentication::class, 'missing Cavv');

/**
 * The refusal names the operation, and it is derived from the class rather than restated — so
 * the operation classes being named for the operations is what keeps the message honest.
 */
it('names the operation it refused in the message', function () {
    cpRegister(['instrument' => cpCard()], ['threeDS' => cpThreeDS('')])->payload();
})->throws(IncompleteAuthentication::class, 'on the "createPaymentMethod" operation');

it('still forwards an attestation whose ECI is absent, because ConnexPay honours it', function () {
    $data = cpAuthorize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => cpCard(),
        'threeDS' => new ThreeDSResult(
            ThreeDSStatus::Successful,
            'cavv-auth-value',
            null,
            'ds-txn-auth',
            'acs-txn-auth',
            ThreeDSVersion::V220,
        ),
    ])->payload();

    expect($data['Card']['ThreeDS'])->toBe([
        'Cavv' => 'cavv-auth-value',
        'Version' => '2.2.0',
        'DirectoryServerTransactionID' => 'ds-txn-auth',
        'AcsTransactionId' => 'acs-txn-auth',
        'ECI' => null,
    ]);
});
