<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload;
use Techork\PaymentService\ConnexPay\Concern\MapsConnexPayOutcome;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Contract\GatewayResult;

/**
 * Takes money that was held — `POST /api/v1/Captures`.
 *
 * Captures the whole authorization and nothing else, because ConnexPay has no other kind
 * ("You can only capture the original amount that was authorized",
 * https://docs.connexpay.com/docs/auth-and-capture). Taking less means {@see PartialCapture};
 * choosing between the two is {@see ConnexPayGateway::capturing()}'s job, since only the gateway
 * has both amounts to compare.
 *
 * The command's amount is deliberately unused: it is what the caller wants to take, the
 * comparison that used it has already happened, and the endpoint accepts no amount at all.
 */
final class Capture
{
    use BuildsConnexPayPayload;
    use MapsConnexPayOutcome;

    private const string CAPTURES_PATH = '/api/v1/Captures';

    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly CaptureCommand $command,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->withCustomerId($this->withIdentifiers([
            'DeviceGuid' => $this->settings->deviceGuid,
            'AuthOnlyGuid' => $this->command->transactionReference,
            'ConnexPayTransaction' => [
                'ExpectedPayments' => 1,
            ],
        ], $this->command->clientUniqueId), $this->command->customer);
    }

    public function capture(): GatewayResult
    {
        try {
            $response = $this->client->post(self::CAPTURES_PATH, $this->payload());
        } catch (GuzzleException $e) {
            return GatewayResult::failed($e->getMessage());
        }

        // ConnexPay nests the captured sale under "sale". The sale's GUID
        // (not the capture's GUID) is what subsequent Returns/Void calls
        // expect, so we unwrap the envelope and read the sale as the
        // primary answer.
        return $this->outcome($response['sale'] ?? $response);
    }
}
