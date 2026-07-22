<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;
use Techork\PaymentService\Revolut\Concern\MerchantCategoryMapper;
use Techork\PaymentService\Revolut\Concern\RevolutRequestParameters;

/**
 * Issues a virtual card via the Revolut Business API.
 *
 * POST /api/1.0/cards — only virtual cards can be created via the API.
 * Requires `money` (the spend limit) and, for these auto-issued cards (no
 * holder / no contacts), `product` (the card program code Revolut mandates).
 * `accountIds` (the `accounts` allow-list) is optional; when omitted the card
 * draws from the business default account.
 *
 * The create response carries the card id, masked PAN (`last_digits`),
 * `expiry` and `state` but never the full PAN / CVV. When
 * `fetchSensitiveDetails` is enabled the request follows up with
 * `GET /cards/{id}/sensitive-details` to surface them; that call needs the
 * `READ_SENSITIVE_CARD_DATA` scope + IP allow-listing, so a failure there
 * degrades gracefully (the card is still returned, PAN / CVV null).
 */
final class IssueVirtualCardRequest extends AbstractRequest
{
    use RevolutRequestParameters;

    private const string CARDS_PATH = '/api/1.0/cards';

    public function getData(): array
    {
        $this->validate('money');

        /** @var Money $money */
        $money = $this->getParameter('money');

        $requestId = $this->getClientUniqueId() ?: Uuid::uuid4()->toString();

        $body = [
            'request_id' => $requestId,
            'virtual' => true,
            'spending_limits' => $this->buildSpendingLimits($money),
        ];

        // Auto-issued virtual cards (no holder / no contacts) require the card
        // product; Revolut expects it as an object keyed by `code`.
        $product = $this->getProduct();
        if ($product !== null && $product !== '') {
            $body['product'] = ['code' => $product];
        }

        $categories = $this->resolveCategories();
        if ($categories !== []) {
            $body['categories'] = $categories;
        }

        // The account allow-list is optional; only forward well-formed UUIDs so
        // stale or malformed credentials can never trip Revolut's validation
        // (an empty result just omits `accounts`, issuing on the default account).
        $accounts = array_values(array_filter(
            $this->getAccountIds() ?? [],
            static fn ($id): bool => is_string($id) && Uuid::isValid($id),
        ));
        if ($accounts !== []) {
            $body['accounts'] = $accounts;
        }

        $spendingPeriod = $this->buildSpendingPeriod();
        if ($spendingPeriod !== null) {
            $body['spending_period'] = $spendingPeriod;
        }

        return $body;
    }

    public function sendData($data): IssueVirtualCardResponse
    {
        try {
            $card = $this->getRevolutClient()->post(self::CARDS_PATH, $data);
        } catch (GuzzleException $e) {
            return new IssueVirtualCardResponse($this, [
                'error' => $e->getMessage(),
            ]);
        }

        $cardId = $card['id'] ?? null;

        if ($cardId !== null && $this->getFetchSensitiveDetails()) {
            $card += $this->fetchSensitiveDetails((string) $cardId);
        }

        return new IssueVirtualCardResponse($this, $card);
    }

    /**
     * Best-effort PAN + CVV lookup. A failure (missing scope, IP not
     * allow-listed) must not orphan the freshly-created card, so it
     * degrades to no sensitive fields rather than throwing.
     *
     * @return array<string, mixed>
     */
    private function fetchSensitiveDetails(string $cardId): array
    {
        try {
            $details = $this->getRevolutClient()->get(self::CARDS_PATH."/{$cardId}/sensitive-details");
        } catch (GuzzleException) {
            return [];
        }

        return [
            'pan' => $details['pan'] ?? null,
            'cvv' => $details['cvv'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveCategories(): array
    {
        $raw = $this->getSpendCategory();
        if ($raw === null || $raw === '') {
            return [];
        }

        $category = CardSpendCategory::tryFrom($raw);
        if ($category === null) {
            return [];
        }

        $mapped = MerchantCategoryMapper::fromCategory($category);

        return $mapped === null ? [] : [$mapped];
    }

    /**
     * @return array{end_date: string, end_date_action: string}|null
     */
    private function buildSpendingPeriod(): ?array
    {
        $days = $this->getValidityDays();
        if ($days === null || $days <= 0) {
            return null;
        }

        return [
            'end_date' => (new \DateTimeImmutable)->modify("+{$days} days")->format('Y-m-d'),
            'end_date_action' => 'terminate',
        ];
    }
}
