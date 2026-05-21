<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Webhook\Contract\EventParser;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;

it('returns registered verifier and parser for a kind', function () {
    $registry = new VerifierRegistry;
    $verifier = Mockery::mock(SignatureVerifier::class);
    $parser = Mockery::mock(EventParser::class);

    $registry->register('Stripe', $verifier, $parser);

    expect($registry->verifier('stripe'))->toBe($verifier)
        ->and($registry->parser('stripe'))->toBe($parser);
});

it('is case-insensitive on the kind', function () {
    $registry = new VerifierRegistry;
    $verifier = Mockery::mock(SignatureVerifier::class);
    $parser = Mockery::mock(EventParser::class);

    $registry->register('NUVEI', $verifier, $parser);

    expect($registry->verifier('nuvei'))->toBe($verifier)
        ->and($registry->verifier('Nuvei'))->toBe($verifier)
        ->and($registry->hasKind('nuvei'))->toBeTrue();
});

it('returns null for unknown kinds', function () {
    $registry = new VerifierRegistry;

    expect($registry->verifier('stripe'))->toBeNull()
        ->and($registry->parser('stripe'))->toBeNull()
        ->and($registry->hasKind('stripe'))->toBeFalse();
});
