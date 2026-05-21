<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function cpEncrypter(): EncryptInterface
{
    return new class implements EncryptInterface
    {
        public function encrypt(string $d): string
        {
            return $d;
        }
    };
}

function cpDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $d): string
        {
            return $d;
        }
    };
}

function cpCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
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
            return [];
        }
    };
}
