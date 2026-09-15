<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Techork\PaymentService\ConnexPay\Concern\LimitWindowMapper;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Gateway\ValueObject\PurchaseTypeBridge;

/**
 * Updates a previously-issued virtual card (spend limit / spend category / limit window).
 *
 * Two endpoints, and the limit window is what chooses between them:
 * `PUT /api/v1/IssueCard/{guid}` for an ordinary card and
 * `PUT /api/v1/IssueCard/LodgedCard/{CardGuid}` for a lodged one. The window is a sound
 * discriminator for this one pair because `LimitWindow` exists in the lodged schema and in no
 * other — sending it to the ordinary endpoint would drop it silently, which is the one outcome an
 * update must not have. What it cannot express is a lodged card updated with no window at all;
 * that goes to the ordinary endpoint, which accepts `AmountLimit` and `PurchaseType` for any card
 * and is why the fields the two bodies share are sent identically.
 *
 * Accepts only fields the integration ever changed in production — `AmountLimit` and
 * `PurchaseType` (mapped from our domain
 * {@see CardSpendCategory}) — plus the window. The
 * other controls both endpoints document (`UsageLimit`, `TerminateDate`, `Activated`, and the
 * lodged MID lists) are out of scope until the command carries them; `UsageLimit` and
 * `TerminateDate` in particular are a standing regression on ordinary cards, not a lodged feature.
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

        $data = [
            'AmountLimit' => (float) $this->settings->formatAmount($this->command->amountLimit),
            'PurchaseType' => str_pad((string) $purchaseType->value, 2, '0', STR_PAD_LEFT),
        ];

        $window = $this->command->limitWindow;
        if ($window !== null) {
            $data['LimitWindow'] = LimitWindowMapper::fromWindow($window);
        }

        return $data;
    }

    public function path(): string
    {
        return $this->command->limitWindow !== null
            ? "/api/v1/IssueCard/LodgedCard/{$this->command->cardGuid}"
            : "/api/v1/IssueCard/{$this->command->cardGuid}";
    }

    public function update(): VirtualCardResult
    {
        $cardGuid = $this->command->cardGuid;

        try {
            $response = $this->client->put($this->path(), $this->payload());
        } catch (GuzzleException $e) {
            return VirtualCardResult::failed($e->getMessage());
        }

        // ConnexPay's ordinary PUT does not echo the card payload back; success is HTTP 200 with no
        // `error` body. The lodged one does echo a card, but the guid it would report is the guid
        // the caller named, so there is nothing to read out of it that we do not already hold.
        // `error` alone decides, deliberately: a body carrying only a `message` is ConnexPay
        // saying something about a change it made, not refusing to make it.
        if (! empty($response['error'])) {
            return VirtualCardResult::failed((string) $response['error']);
        }

        return VirtualCardResult::succeeded(cardGuid: $cardGuid);
    }
}
