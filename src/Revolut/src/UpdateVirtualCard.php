<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use GuzzleHttp\Exception\GuzzleException;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Revolut\Concern\MerchantCategoryMapper;
use Techork\PaymentService\Revolut\Concern\RevolutExpiry;
use Techork\PaymentService\Revolut\Concern\RevolutRequestParameters;

/**
 * Adjusts a live card's spend limit and merchant controls.
 */
final readonly class UpdateVirtualCard
{
    use RevolutRequestParameters;

    public function __construct(
        private RevolutHttpClientInterface $client,
        private CardSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(UpdateCardCommand $command): array
    {
        $body = [
            'spending_limits' => $this->buildSpendingLimits($command->amountLimit, $this->settings->spendLimitPeriod),
        ];

        // Unconditional: the command carries a {@see CardSpendCategory}, so the mapper always
        // has a bucket to name. The request this replaced read the category as a raw string off
        // the parameter bag and had to cope with it being absent or unparseable.
        $body['categories'] = MerchantCategoryMapper::fromCategory($command->spendCategory);

        return $body;
    }

    public function update(UpdateCardCommand $command): VirtualCardResult
    {
        try {
            $card = $this->client->patch("/api/1.0/cards/{$command->cardGuid}", $this->payload($command));
        } catch (GuzzleException $e) {
            return VirtualCardResult::failed($e->getMessage());
        }

        // PATCH echoes the updated card; a sparse body still resolves to the card we updated,
        // which is the one the caller named.
        return VirtualCardResult::succeeded(
            cardGuid: (string) ($card['id'] ?? $command->cardGuid),
            expirationDate: RevolutExpiry::normalize($card['expiry'] ?? null),
            status: $card['state'] ?? null,
        );
    }
}
