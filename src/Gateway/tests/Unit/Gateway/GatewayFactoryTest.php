<?php

declare(strict_types=1);

use Omnipay\Common\AbstractGateway;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\GatewayFactory;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function makeCredential(string $name = 'Stripe', array $credentials = [], ?GatewayId $key = null): GatewayCredential
{
    $id = $key ?? GatewayId::generate();

    return new readonly class($id, $name, $credentials) implements GatewayCredential
    {
        public function __construct(private GatewayId $id, private string $name, private array $credentials) {}
        public function getId(): GatewayId { return $this->id; }
        public function getGatewayName(): string { return $this->name; }
        public function getCredentials(): array { return $this->credentials; }
    };
}

function makeGatewayFactory(): GatewayFactory
{
    return new GatewayFactory(Mockery::mock(CustomerRepository::class));
}

it('registers and lists gateway mappings', function () {
    $factory = makeGatewayFactory();
    $factory->replace(['TestGateway' => AbstractGateway::class]);

    expect($factory->all())->toBe(['TestGateway' => AbstractGateway::class]);
});

it('throws RuntimeException for unregistered gateway name', function () {
    $factory = makeGatewayFactory();
    $factory->replace(['Stripe' => 'SomeClass']);

    $credential = makeCredential(name: 'Unknown');

    $factory->createForCredential($credential);
})->throws(RuntimeException::class, "Gateway 'Unknown' is not registered.");

it('caches gateway instances per credential key', function () {
    $gateway = Mockery::mock(Gateway::class);

    $factory = Mockery::mock(GatewayFactory::class);
    $factory->shouldReceive('createForCredential')->andReturn($gateway);

    $gwId = GatewayId::generate();
    $credential = makeCredential(credentials: ['apiKey' => 'sk_test'], key: $gwId);

    $first = $factory->createForCredential($credential);
    $second = $factory->createForCredential($credential);

    expect($first)->toBe($second);
});

it('creates separate instances for different credential keys', function () {
    $gw1 = Mockery::mock(Gateway::class);
    $gw2 = Mockery::mock(Gateway::class);

    $factory = Mockery::mock(GatewayFactory::class);
    $factory->shouldReceive('createForCredential')->andReturn($gw1, $gw2);

    $cred1 = makeCredential(key: GatewayId::generate());
    $cred2 = makeCredential(key: GatewayId::generate());

    $first = $factory->createForCredential($cred1);
    $second = $factory->createForCredential($cred2);

    expect($first)->not->toBe($second);
});
