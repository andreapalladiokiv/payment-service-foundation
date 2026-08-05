<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Neutrino\CardFunding;
use Techork\PaymentService\Neutrino\CardIntelligence;
use Techork\PaymentService\Neutrino\CardIntelligenceProvider;
use Techork\PaymentService\Neutrino\IpIntelligence;
use Techork\PaymentService\Neutrino\IpIntelligenceProvider;
use Techork\PaymentService\Neutrino\NeutrinoCardFactSupplier;
use Techork\PaymentService\Neutrino\NeutrinoIpFactSupplier;

function cardProviderReturning(CardIntelligence|Throwable|null $answer): CardIntelligenceProvider
{
    return new readonly class($answer) implements CardIntelligenceProvider
    {
        public function __construct(private CardIntelligence|Throwable|null $answer) {}

        public function lookupBin(string $bin, ?string $ip = null): ?CardIntelligence
        {
            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return $this->answer;
        }
    };
}

function ipProviderReturning(IpIntelligence|Throwable|null $answer): IpIntelligenceProvider
{
    return new readonly class($answer) implements IpIntelligenceProvider
    {
        public function __construct(private IpIntelligence|Throwable|null $answer) {}

        public function lookupIp(string $ip): ?IpIntelligence
        {
            if ($this->answer instanceof Throwable) {
                throw $this->answer;
            }

            return $this->answer;
        }
    };
}

it('exposes BIN intelligence under the card source facts', function () {
    $supplier = new NeutrinoCardFactSupplier(
        cardProviderReturning(new CardIntelligence(new Country('GB'), CardFunding::Credit, isPrepaid: true, isCommercial: true)),
        '411111',
        '203.0.113.7',
    );

    expect($supplier->facts())->toBe([
        'payment_method' => [
            'source' => [
                'issuer_country' => 'GB',
                'funding' => 'credit',
                'is_prepaid' => true,
                'is_commercial' => true,
            ],
        ],
    ]);
});

it('exposes IP intelligence under the connection facts, including the host domain', function () {
    $supplier = new NeutrinoIpFactSupplier(
        ipProviderReturning(new IpIntelligence(new Country('DE'), isProxy: true, isVpn: true, hostDomain: 'vpn.example')),
        '203.0.113.7',
    );

    expect($supplier->facts())->toBe([
        'payment_method' => [
            'connection' => [
                'country' => 'DE',
                'is_proxy' => true,
                'is_vpn' => true,
                'host_domain' => 'vpn.example',
            ],
        ],
    ]);
});

it('emits nothing when a lookup finds nothing, so absence stays distinguishable from a negative', function () {
    // Emitting is_prepaid => false for a BIN we could not resolve would let a
    // rule match on a fact we never learned.
    expect(new NeutrinoCardFactSupplier(cardProviderReturning(null), '411111')->facts())->toBe([])
        ->and(new NeutrinoIpFactSupplier(ipProviderReturning(null), '203.0.113.7')->facts())->toBe([]);
});

it('treats a provider failure as a missing signal rather than an exception', function () {
    $boom = new RuntimeException('neutrino is down');

    expect(new NeutrinoCardFactSupplier(cardProviderReturning($boom), '411111')->facts())->toBe([])
        ->and(new NeutrinoIpFactSupplier(ipProviderReturning($boom), '203.0.113.7')->facts())->toBe([]);
});

it('keeps a null issuer country null rather than stringifying it', function () {
    $supplier = new NeutrinoCardFactSupplier(
        cardProviderReturning(new CardIntelligence(null, CardFunding::Unknown)),
        '411111',
    );

    $source = $supplier->facts()['payment_method']['source'];

    expect($source['issuer_country'])->toBeNull()
        ->and($source['funding'])->toBe('unknown');
});
