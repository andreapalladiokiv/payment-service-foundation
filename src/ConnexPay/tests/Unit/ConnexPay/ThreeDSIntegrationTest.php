<?php

declare(strict_types=1);

use Money\Currency;
use Money\Money;
use Omnipay\Common\Http\PsrClient as OmnipayClient;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\ConnexPay\AuthorizeRequest;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function threeDSCpEncrypter(): EncryptInterface
{
    return new class implements EncryptInterface { public function encrypt(string $d): string { return $d; } };
}

function threeDSCpDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface { public function decrypt(string $d): string { return $d; } };
}

function threeDSCpCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential {
        public function getId(): GatewayId { return GatewayId::generate(); }
        public function getGatewayName(): string { return 'ConnexPay'; }
        public function getCredentials(): array { return []; }
    };
}

function threeDSCpCard(): CreditCard
{
    return new CreditCard(
        Number::fromNumber('4012000098765439', threeDSCpEncrypter()),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test User'),
        Cvc::fromCvc('999', threeDSCpEncrypter()),
    );
}

// ──────────────────────────────────────────────
//  CreditCard — ThreeDS in Card
// ──────────────────────────────────────────────

it('includes ThreeDS in Card when threeDS present', function () {
    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-auth-value',
        ECICode::MastercardSuccessful,
        'ds-txn-auth',
        'acs-txn-auth',
        ThreeDSVersion::V220,
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => threeDSCpCard(),
        'gateway' => threeDSCpCredential(),
        'decrypter' => threeDSCpDecrypter(),
        'deviceGuid' => 'device-123',
        'threeDS' => $threeDS,
    ]);

    $data = $request->getData();

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
    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(5000, new Currency('USD')),
        'instrument' => threeDSCpCard(),
        'gateway' => threeDSCpCredential(),
        'decrypter' => threeDSCpDecrypter(),
        'deviceGuid' => 'device-123',
    ]);

    $data = $request->getData();

    expect($data['Card'])->not->toHaveKey('ThreeDS');
});

// ──────────────────────────────────────────────
//  Cash — no Card, no ThreeDS
// ──────────────────────────────────────────────

it('excludes ThreeDS when instrument is Cash', function () {
    $threeDS = new ThreeDSResult(
        ThreeDSStatus::Successful,
        'cavv-ignored',
        ECICode::VisaSuccessful,
        'ds-txn-ignored',
        'acs-txn-ignored',
        ThreeDSVersion::V220,
    );

    $request = new AuthorizeRequest(new OmnipayClient, new HttpRequest);
    $request->initialize([
        'money' => new Money(3000, new Currency('USD')),
        'instrument' => new Cash,
        'gateway' => threeDSCpCredential(),
        'decrypter' => threeDSCpDecrypter(),
        'deviceGuid' => 'device-123',
        'threeDS' => $threeDS,
    ]);

    $data = $request->getData();

    expect($data)->not->toHaveKey('Card');
});
