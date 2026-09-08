<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * Kills a virtual card — `POST /api/v1/TerminateCard/{cardGuid}` on the Purchases API.
 *
 * Answers with a bare {@see GatewayResult}: there is no card left to describe, only whether it
 * is gone. The card guid is the reference, because the endpoint names nothing else — the body it
 * answers with carries a termination date and no identity.
 */
final class TerminateCard
{
    public function __construct(
        private readonly TerminateCardCommand $command,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * The card is named in the path, so there is nothing to send. Stated rather than left
     * implicit: an empty body is what the endpoint wants, not an omission.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [];
    }

    public function terminate(): GatewayResult
    {
        $cardGuid = $this->command->cardGuid;

        try {
            $this->client->post("/api/v1/TerminateCard/{$cardGuid}", $this->payload());
        } catch (GuzzleException $e) {
            return GatewayResult::failed($e->getMessage());
        }

        return GatewayResult::succeeded($cardGuid);
    }
}
