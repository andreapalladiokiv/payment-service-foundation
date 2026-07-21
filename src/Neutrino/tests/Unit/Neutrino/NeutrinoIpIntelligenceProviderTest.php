<?php

declare(strict_types=1);

use Techork\PaymentService\Neutrino\NeutrinoIpIntelligenceProvider;

it('maps an ip-info response to ip intelligence', function () {
    $provider = new NeutrinoIpIntelligenceProvider(fakeNeutrinoClient([
        'ip-info' => ['country-code' => 'DE', 'is-vpn' => true, 'is-proxy' => true, 'hostname' => 'host.example'],
    ]));

    $intel = $provider->lookupIp('203.0.113.7');

    expect((string) $intel->country)->toBe('DE')
        ->and($intel->isVpn)->toBeTrue()
        ->and($intel->isProxy)->toBeTrue()
        ->and($intel->hostDomain)->toBe('host.example');
});

it('defaults reputation flags to false when absent', function () {
    $provider = new NeutrinoIpIntelligenceProvider(fakeNeutrinoClient([
        'ip-info' => ['country-code' => 'US'],
    ]));

    $intel = $provider->lookupIp('203.0.113.7');

    expect((string) $intel->country)->toBe('US')
        ->and($intel->isProxy)->toBeFalse()
        ->and($intel->isVpn)->toBeFalse()
        ->and($intel->hostDomain)->toBeNull();
});

it('returns null when the response is empty', function () {
    $provider = new NeutrinoIpIntelligenceProvider(fakeNeutrinoClient([]));

    expect($provider->lookupIp('203.0.113.7'))->toBeNull();
});

it('returns null (fail-soft) when the transport fails', function () {
    $provider = new NeutrinoIpIntelligenceProvider(fakeNeutrinoClient(throws: new RuntimeException('down')));

    expect($provider->lookupIp('203.0.113.7'))->toBeNull();
});
