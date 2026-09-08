<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use GuzzleHttp\Exception\GuzzleException;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * Kills a card.
 *
 * Answers with a bare {@see GatewayResult}: there is no card left to describe, only whether it
 * is gone.
 */
final readonly class TerminateCard
{
    public function __construct(private RevolutHttpClientInterface $client) {}

    public function terminate(TerminateCardCommand $command): GatewayResult
    {
        try {
            $this->client->delete("/api/1.0/cards/{$command->cardGuid}");
        } catch (GuzzleException $e) {
            return GatewayResult::failed($e->getMessage());
        }

        return GatewayResult::succeeded($command->cardGuid);
    }
}
