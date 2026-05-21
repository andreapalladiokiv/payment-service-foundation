<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Webhook;

use Psr\Http\Message\ServerRequestInterface;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\PaymentGatewayRouter;
use Techork\PaymentService\Gateway\Webhook\Contract\GatewayMatch;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;

/**
 * Framework-agnostic webhook router. Mirrors {@see PaymentGatewayRouter}
 * but for inbound traffic:
 *
 *   - identifyGateway: run every candidate credential through the kind-appropriate
 *     {@see SignatureVerifier}; the first credential whose signature validates
 *     is the tenant. Returns a {@see GatewayMatch} with the external id
 *     extracted by the kind's parser.
 *
 *   - dispatch: given a {@see StoredWebhookCall} DTO, re-parse the payload and
 *     invoke the handler registered for `(kind, event-type)`.
 */
class WebhookRouter
{
    public function __construct(
        private readonly GatewayCredentialRepository $credentials,
        private readonly VerifierRegistry $verifiers,
        private readonly HandlerRegistry $handlers,
    ) {}

    public function identifyGateway(ServerRequestInterface $request): ?GatewayMatch
    {
        foreach ($this->credentials->all() as $credential) {
            $kind = $credential->getGatewayName();
            $verifier = $this->verifiers->verifier($kind);
            if ($verifier === null) {
                continue;
            }

            $parser = $this->verifiers->parser($kind);

            if ($parser === null) {
                continue;
            }

            if (! $verifier->verify($request, $credential)) {
                continue;
            }

            $parsedBody = $request->getParsedBody();
            $payload = is_array($parsedBody) ? $parsedBody : [];

            $parsed = $parser->parse($payload);

            return new GatewayMatch(
                gatewayId: $credential->getId(),
                kind: strtolower($kind),
                externalId: $parsed->externalId,
            );
        }

        return null;
    }

    public function dispatch(StoredWebhookCall $call): HandlerOutcome
    {
        $parser = $this->verifiers->parser($call->kind);
        if ($parser === null) {
            return HandlerOutcome::Skipped;
        }

        $parsed = $parser->parse($call->payload);
        $handler = $this->handlers->resolve($call->kind, $parsed->type);
        if ($handler === null) {
            return HandlerOutcome::Skipped;
        }

        return $handler($parsed->native, $call->gatewayId);
    }
}
