<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
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
    return new GatewayFactory(
        Mockery::mock(CustomerRepository::class, ['findByInstrument' => null]),
        Mockery::mock(DecryptInterface::class),
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
    );
}

it('registers and lists gateway mappings', function () {
    $factory = makeGatewayFactory();
    // The registry maps a name to a class string and does not load it; any class name will do,
    // and it used to be Omnipay's `AbstractGateway` for no reason beyond it being on hand.
    $factory->replace(['TestGateway' => Gateway::class]);

    expect($factory->all())->toBe(['TestGateway' => Gateway::class]);
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
