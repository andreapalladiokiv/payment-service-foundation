<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use DateMalformedStringException;
use DateTimeImmutable;
use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Revolut\Concern\MerchantCategoryMapper;
use Techork\PaymentService\Revolut\Concern\RevolutExpiry;
use Techork\PaymentService\Revolut\Concern\RevolutRequestParameters;

/**
 * Issues a virtual card through the Revolut Business API.
 *
 * POST /api/1.0/cards — only virtual cards can be created this way. The create response carries
 * the card id, masked PAN, expiry and state but never the full PAN or CVV; when the deployment
 * asks for them, a follow-up GET /cards/{id}/sensitive-details supplies them. That call needs the
 * `READ_SENSITIVE_CARD_DATA` scope and IP allow-listing, so a failure there degrades to a card
 * with no sensitive fields rather than orphaning one that was just created.
 *
 * Three parts, and only the middle one touches the network: {@see payload()} turns the command
 * into the body, {@see issue()} sends it, and the mapping to a {@see VirtualCardResult} happens
 * where the provider's answer is still in hand. There is no request object to construct and send,
 * and no response object wrapping the payload for something else to re-read — Omnipay needed both
 * because a request was a bag with a lifecycle and a response had to be interrogated through a
 * common interface. Neither is true here.
 */
final readonly class IssueVirtualCard
{
    use RevolutRequestParameters;

    private const string CARDS_PATH = '/api/1.0/cards';

    public function __construct(
        private RevolutHttpClientInterface $client,
        private CardSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(IssueCardCommand $command): array
    {
        $body = [
            'request_id' => $command->clientUniqueId ?: Uuid::uuid4()->toString(),
            'virtual' => true,
            'spending_limits' => $this->buildSpendingLimits($command->amountLimit, $this->settings->spendLimitPeriod),
        ];

        // Auto-issued virtual cards (no holder, no contacts) require the card product; Revolut
        // expects it as an object keyed by `code`.
        if ($this->settings->product !== null && $this->settings->product !== '') {
            $body['product'] = ['code' => $this->settings->product];
        }

        // Unconditional: the command carries a {@see CardSpendCategory}, so the mapper always
        // has a bucket to name. The request this replaced read the category as a raw string off
        // the parameter bag and had to cope with it being absent or unparseable.
        $body['categories'] = MerchantCategoryMapper::fromCategory($command->spendCategory);

        // The account allow-list is optional; only well-formed UUIDs are forwarded so a stale or
        // malformed credential cannot trip Revolut's validation. An empty result omits `accounts`
        // and the card is issued on the business default account.
        $accounts = array_values(array_filter(
            $this->settings->accountIds ?? [],
            static fn (mixed $id): bool => is_string($id) && Uuid::isValid($id),
        ));
        if ($accounts !== []) {
            $body['accounts'] = $accounts;
        }

        $spendingPeriod = $this->spendingPeriod();
        if ($spendingPeriod !== null) {
            $body['spending_period'] = $spendingPeriod;
        }

        return $body;
    }

    public function issue(IssueCardCommand $command): VirtualCardResult
    {
        try {
            $card = $this->client->post(self::CARDS_PATH, $this->payload($command));
        } catch (GuzzleException $e) {
            return VirtualCardResult::failed($e->getMessage());
        }

        $cardId = $card['id'] ?? null;

        if ($cardId === null) {
            return VirtualCardResult::failed($card['message'] ?? 'Revolut card issuance failed.');
        }

        if ($this->settings->fetchSensitiveDetails) {
            $card += $this->sensitiveDetails((string) $cardId);
        }

        return VirtualCardResult::succeeded(
            cardGuid: (string) $cardId,
            cardNumber: $card['pan'] ?? null,
            cvv: $card['cvv'] ?? null,
            expirationDate: RevolutExpiry::normalize($card['expiry'] ?? null),
            status: $card['state'] ?? null,
        );
    }

    /**
     * Best-effort PAN and CVV lookup. A failure — missing scope, IP not allow-listed — must not
     * orphan the card that was just created, so it degrades to no sensitive fields.
     *
     * @return array<string, mixed>
     */
    private function sensitiveDetails(string $cardId): array
    {
        try {
            $details = $this->client->get(self::CARDS_PATH."/{$cardId}/sensitive-details");
        } catch (GuzzleException) {
            return [];
        }

        return ['pan' => $details['pan'] ?? null, 'cvv' => $details['cvv'] ?? null];
    }

    /**
     * @return array{end_date: string, end_date_action: string}|null
     *
     * @throws DateMalformedStringException
     */
    private function spendingPeriod(): ?array
    {
        $days = $this->settings->validityDays;

        if ($days === null || $days <= 0) {
            return null;
        }

        return [
            'end_date' => new DateTimeImmutable()->modify("+{$days} days")->format('Y-m-d'),
            'end_date_action' => 'terminate',
        ];
    }
}
