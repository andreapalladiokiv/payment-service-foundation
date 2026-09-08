<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\ValueObject\PurchaseTypeBridge;

/**
 * Updates a previously-issued virtual card (spend limit / spend category).
 *
 * PUT /api/v1/IssueCard/{guid} on the Purchases API. Accepts only fields the
 * legacy integration ever changed in production — `AmountLimit` and
 * `PurchaseType` (mapped from our domain
 * {@see \Techork\PaymentService\Gateway\ValueObject\CardSpendCategory}). Other
 * ConnexPay-supported edits (suspend / unsuspend, cardholder name) are
 * intentionally out of scope until requested.
 */
final class UpdateVirtualCard
{
    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly UpdateCardCommand $command,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $purchaseType = PurchaseTypeBridge::fromCategory($this->command->spendCategory);

        return [
            'AmountLimit' => (float) $this->settings->formatAmount($this->command->amountLimit),
            'PurchaseType' => str_pad((string) $purchaseType->value, 2, '0', STR_PAD_LEFT),
        ];
    }

    public function update(): VirtualCardResult
    {
        $cardGuid = $this->command->cardGuid;

        try {
            $response = $this->client->put("/api/v1/IssueCard/{$cardGuid}", $this->payload());
        } catch (GuzzleException $e) {
            return VirtualCardResult::failed($e->getMessage());
        }

        // ConnexPay's PUT does not echo the card payload back; success is HTTP 200 with no
        // `error` body. The card we updated is the card the caller named, so that is the guid
        // reported — there is no other one to report.
        // `error` alone decides, deliberately: a body carrying only a `message` is ConnexPay
        // saying something about a change it made, not refusing to make it.
        if (! empty($response['error'])) {
            return VirtualCardResult::failed((string) $response['error']);
        }

        return VirtualCardResult::succeeded(cardGuid: $cardGuid);
    }
}
