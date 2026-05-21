<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook;

use Techork\PaymentService\Gateway\Webhook\Contract\EventParser;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;

/**
 * Maps gateway kind (lowercase `gateway_name`) to the pair of protocol
 * primitives {@see WebhookRouter} needs for that kind: a signature verifier
 * and a payload parser.
 */
final class VerifierRegistry
{
    /** @var array<string, SignatureVerifier> */
    private array $verifiers = [];

    /** @var array<string, EventParser> */
    private array $parsers = [];

    public function register(string $kind, SignatureVerifier $verifier, EventParser $parser): void
    {
        $key = strtolower($kind);
        $this->verifiers[$key] = $verifier;
        $this->parsers[$key] = $parser;
    }

    public function verifier(string $kind): ?SignatureVerifier
    {
        return $this->verifiers[strtolower($kind)] ?? null;
    }

    public function parser(string $kind): ?EventParser
    {
        return $this->parsers[strtolower($kind)] ?? null;
    }

    public function hasKind(string $kind): bool
    {
        return isset($this->verifiers[strtolower($kind)]);
    }
}
