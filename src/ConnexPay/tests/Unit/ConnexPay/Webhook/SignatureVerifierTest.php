<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Webhook\Contract\InboundWebhook;

use Nyholm\Psr7\ServerRequest;
use Techork\PaymentService\ConnexPay\Webhook\SignatureVerifier;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function makeConnexPayCredential(array $credentials): GatewayCredential
{
    return new readonly class($credentials) implements GatewayCredential
    {
        public function __construct(private array $credentials) {}

        public function getId(): GatewayId
        {
            return GatewayId::generate();
        }

        public function getGatewayName(): string
        {
            return 'ConnexPay';
        }

        public function getCredentials(): array
        {
            return $this->credentials;
        }
    };
}

function makeRequestWithBasic(string $username, string $password): InboundWebhook
{
    return InboundWebhook::from(new ServerRequest('POST', '/webhooks', [
        'Authorization' => 'Basic '.base64_encode($username.':'.$password),
    ]));
}

it('accepts a request with matching Basic credentials', function () {
    $verifier = new SignatureVerifier;
    $gateway = makeConnexPayCredential(['username' => 'cp-bridge', 'password' => 's3cret!']);

    expect($verifier->verify(makeRequestWithBasic('cp-bridge', 's3cret!'), $gateway))->toBeTrue();
});

it('rejects a wrong username', function () {
    $verifier = new SignatureVerifier;
    $gateway = makeConnexPayCredential(['username' => 'cp-bridge', 'password' => 's3cret!']);

    expect($verifier->verify(makeRequestWithBasic('bad', 's3cret!'), $gateway))->toBeFalse();
});

it('rejects a wrong password', function () {
    $verifier = new SignatureVerifier;
    $gateway = makeConnexPayCredential(['username' => 'cp-bridge', 'password' => 's3cret!']);

    expect($verifier->verify(makeRequestWithBasic('cp-bridge', 'WRONG'), $gateway))->toBeFalse();
});

it('rejects a request when Authorization header is missing', function () {
    $verifier = new SignatureVerifier;
    $gateway = makeConnexPayCredential(['username' => 'cp-bridge', 'password' => 's3cret!']);

    $request = InboundWebhook::from(new ServerRequest('POST', '/webhooks'));

    expect($verifier->verify($request, $gateway))->toBeFalse();
});

it('rejects a request when scheme is not Basic', function () {
    $verifier = new SignatureVerifier;
    $gateway = makeConnexPayCredential(['username' => 'cp-bridge', 'password' => 's3cret!']);

    $request = InboundWebhook::from(new ServerRequest('POST', '/webhooks', ['Authorization' => 'Bearer token-123']));

    expect($verifier->verify($request, $gateway))->toBeFalse();
});

it('fails closed when webhook credentials are not configured', function () {
    $verifier = new SignatureVerifier;
    $gateway = makeConnexPayCredential([]);

    expect($verifier->verify(makeRequestWithBasic('any', 'any'), $gateway))->toBeFalse();
});

it('fails closed when one of the two webhook credentials is missing', function () {
    $verifier = new SignatureVerifier;
    $gateway = makeConnexPayCredential(['username' => 'cp-bridge']);

    expect($verifier->verify(makeRequestWithBasic('cp-bridge', ''), $gateway))->toBeFalse();
});
