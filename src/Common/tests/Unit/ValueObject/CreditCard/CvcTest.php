<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;

$sharedDecrypter = new class implements DecryptInterface
{
    public function decrypt(string $data): string
    {
        return base64_decode($data);
    }
};
$sharedEncrypter = new class implements EncryptInterface
{
    public function encrypt(string $data): string
    {
        return base64_encode($data);
    }
};

it('creates Cvc from string via fromCvc and decrypts back', function () use ($sharedEncrypter, $sharedDecrypter) {
    $cvc = Cvc::fromCvc('456', $sharedEncrypter);

    expect($cvc->getCvc($sharedDecrypter))->toBe('456');
});

it('returns null from getCvc when Cvc created without data', function () use ($sharedDecrypter) {
    $cvc = new Cvc;

    expect($cvc->getCvc($sharedDecrypter))->toBeNull();
});

it('converts Cvc to empty string via __toString', function () {
    expect((string) new Cvc)->toBe('');
});

it('serializes Cvc to masked value via jsonSerialize', function () {
    $cvc = new Cvc;

    expect($cvc->jsonSerialize())->toBe('***')
        ->and(json_encode($cvc))->toBe('"***"');
});

it('drops encrypted CVC on PHP serialize/unserialize round-trip', function () use ($sharedEncrypter, $sharedDecrypter) {
    $cvc = Cvc::fromCvc('789', $sharedEncrypter);
    expect($cvc->getCvc($sharedDecrypter))->toBe('789');

    $serialised = serialize($cvc);

    // Encrypted CVC bytes (base64 'Nzg5') must not appear in the wire blob.
    expect($serialised)->not->toContain(base64_encode('789'));

    /** @var Cvc $rehydrated */
    $rehydrated = unserialize($serialised);
    expect($rehydrated)->toBeInstanceOf(Cvc::class)
        ->and($rehydrated->getCvc($sharedDecrypter))->toBeNull()
        ->and((string) $rehydrated)->toBe('')
        ->and($rehydrated->jsonSerialize())->toBe('***');
});

it('keeps CVC available within a single process before serialization', function () use ($sharedEncrypter, $sharedDecrypter) {
    $cvc = Cvc::fromCvc('321', $sharedEncrypter);

    // Multiple in-memory reads are fine — the wipe happens only at serialize boundaries.
    expect($cvc->getCvc($sharedDecrypter))->toBe('321')
        ->and($cvc->getCvc($sharedDecrypter))->toBe('321');
});
