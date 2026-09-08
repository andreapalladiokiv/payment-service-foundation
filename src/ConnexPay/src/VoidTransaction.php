<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload;
use Techork\PaymentService\ConnexPay\Concern\MapsConnexPayOutcome;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * Releases a hold that will not be taken — `POST /api/v1/void`.
 *
 * `VoidTransaction` rather than `Void`, only because `void` is a reserved word and cannot name a
 * class. The endpoint is ConnexPay's Void; the role it serves is the contract's `cancel`.
 */
final class VoidTransaction
{
    use BuildsConnexPayPayload;
    use MapsConnexPayOutcome;

    private const string VOID_PATH = '/api/v1/void';

    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly CancelCommand $command,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->withIdentifiers([
            'DeviceGuid' => $this->settings->deviceGuid,
            'AuthOnlyGuid' => $this->command->transactionReference,
        ], $this->command->clientUniqueId);
    }

    public function cancel(): GatewayResult
    {
        try {
            return $this->outcome($this->client->post(self::VOID_PATH, $this->payload()));
        } catch (GuzzleException $e) {
            return GatewayResult::failed($e->getMessage());
        }
    }
}
