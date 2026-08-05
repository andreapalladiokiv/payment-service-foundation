<?php

declare(strict_types=1);

use Techork\PaymentService\Neutrino\NeutrinoHttpClientInterface;

/**
 * A fake Neutrino HTTP client returning a per-endpoint canned response
 * (or throwing).
 *
 * @param  array<string, array<string, mixed>>  $responses  keyed by endpoint
 */
function fakeNeutrinoClient(array $responses = [], ?Throwable $throws = null): NeutrinoHttpClientInterface
{
    return new readonly class($responses, $throws) implements NeutrinoHttpClientInterface
    {
        /** @param array<string, array<string, mixed>> $responses */
        public function __construct(private array $responses, private ?Throwable $throws) {}

        public function request(string $endpoint, array $params): array
        {
            if ($this->throws !== null) {
                throw $this->throws;
            }

            return $this->responses[$endpoint] ?? [];
        }
    };
}
