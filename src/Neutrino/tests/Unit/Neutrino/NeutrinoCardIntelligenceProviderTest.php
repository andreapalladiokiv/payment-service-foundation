<?php

declare(strict_types=1);

use Techork\PaymentService\Neutrino\CardFunding;
use Techork\PaymentService\Neutrino\NeutrinoCardIntelligenceProvider;

it('maps a bin-lookup response to card intelligence', function () {
    $provider = new NeutrinoCardIntelligenceProvider(fakeNeutrinoClient([
        'bin-lookup' => ['country-code' => 'GB', 'is-prepaid' => true, 'is-commercial' => false],
    ]));

    $intel = $provider->lookupBin('411111');

    expect((string) $intel->issuerCountry)->toBe('GB')
        ->and($intel->isPrepaid)->toBeTrue()
        ->and($intel->isCommercial)->toBeFalse()
        ->and($intel->funding)->toBe(CardFunding::Unknown);
});

it('returns null issuer country when the code is missing or invalid', function (array $response) {
    $provider = new NeutrinoCardIntelligenceProvider(fakeNeutrinoClient(['bin-lookup' => $response]));

    expect($provider->lookupBin('411111')->issuerCountry)->toBeNull();
})->with([
    [['is-prepaid' => false]],
    [['country-code' => '']],
    [['country-code' => 'XX']],
]);

it('returns null when the response is empty', function () {
    $provider = new NeutrinoCardIntelligenceProvider(fakeNeutrinoClient());

    expect($provider->lookupBin('411111'))->toBeNull();
});

it('returns null (fail-soft) when the transport fails', function () {
    $provider = new NeutrinoCardIntelligenceProvider(fakeNeutrinoClient(throws: new RuntimeException('down')));

    expect($provider->lookupBin('411111'))->toBeNull();
});
