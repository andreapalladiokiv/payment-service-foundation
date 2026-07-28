<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Neutrino\CardFunding;
use Techork\PaymentService\Neutrino\CardIntelligence;
use Techork\PaymentService\Neutrino\CardIntelligenceProvider;
use Techork\PaymentService\Neutrino\IpIntelligence;
use Techork\PaymentService\Neutrino\IpIntelligenceProvider;

it('lets a card-intelligence provider be implemented and return BIN facts', function () {
    $provider = new class implements CardIntelligenceProvider
    {
        public function lookupBin(string $bin, ?string $ip = null): ?CardIntelligence
        {
            return new CardIntelligence(new Country('GB'), CardFunding::Credit, isPrepaid: false, isCommercial: true);
        }
    };

    $intel = $provider->lookupBin('411111');

    expect((string) $intel->issuerCountry)->toBe('GB')
        ->and($intel->funding)->toBe(CardFunding::Credit)
        ->and($intel->isCommercial)->toBeTrue();
});

it('lets an ip-intelligence provider be implemented and return geo facts', function () {
    $provider = new class implements IpIntelligenceProvider
    {
        public function lookupIp(string $ip): ?IpIntelligence
        {
            return new IpIntelligence(new Country('DE'), isProxy: true);
        }
    };

    $intel = $provider->lookupIp('203.0.113.7');

    expect((string) $intel->country)->toBe('DE')
        ->and($intel->isProxy)->toBeTrue();
});
