<?php

declare(strict_types=1);

use Omnipay\Common\Message\RequestInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;
use Techork\PaymentService\ConnexPay\ConnexPayResponse;
use Techork\PaymentService\Gateway\Contract\CardChecksProvider;

function makeConnexPayResponse(array $data): ConnexPayResponse
{
    $request = Mockery::mock(RequestInterface::class);

    return new ConnexPayResponse($request, $data);
}

it('implements CardChecksProvider', function () {
    expect(makeConnexPayResponse([]))->toBeInstanceOf(CardChecksProvider::class);
});

it('returns null for all checks when codes absent', function () {
    $response = makeConnexPayResponse(['wasProcessed' => true, 'guid' => 'abc']);

    expect($response->getAddressLineCheck())->toBeNull()
        ->and($response->getPostalCodeCheck())->toBeNull()
        ->and($response->getCvcCheck())->toBeNull();
});

it('decomposes Y AVS into (Pass, Pass)', function () {
    $response = makeConnexPayResponse(['addressVerificationCode' => 'Y']);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Pass)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Pass);
});

it('decomposes A AVS into (Pass, Fail)', function () {
    $response = makeConnexPayResponse(['addressVerificationCode' => 'A']);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Pass)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Fail);
});

it('treats 0 AVS as Unchecked (not run)', function () {
    $response = makeConnexPayResponse(['addressVerificationCode' => '0']);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Unchecked)
        ->and($response->getPostalCodeCheck())->toBe(CheckResult::Unchecked);
});

it('maps CVV M to Pass and N to Fail', function () {
    expect(makeConnexPayResponse(['cvvVerificationCode' => 'M'])->getCvcCheck())
        ->toBe(CheckResult::Pass)
        ->and(makeConnexPayResponse(['cvvVerificationCode' => 'N'])->getCvcCheck())
        ->toBe(CheckResult::Fail);
});

it('accepts PascalCase keys (defensive)', function () {
    $response = makeConnexPayResponse([
        'AddressVerificationCode' => 'Y',
        'CvvVerificationCode' => 'M',
    ]);

    expect($response->getAddressLineCheck())->toBe(CheckResult::Pass)
        ->and($response->getCvcCheck())->toBe(CheckResult::Pass);
});
