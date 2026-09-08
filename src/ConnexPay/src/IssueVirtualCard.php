<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\ValueObject\PurchaseTypeBridge;

/**
 * Issues a virtual card against money already taken — `POST /api/v1/IssueCard` on the Purchases
 * API (sandboxpurchasesapi.connexpay.com / purchasesapi.connexpay.com), which is a different host
 * and a different token from the sales API every other operation here talks to.
 *
 * The `IncomingTransactionCode` is resolved before this is built, by
 * {@see ConnexPayGateway::issuing()} — it is the sale's code, and finding it may cost a
 * Search/Sales round trip, which is exactly the kind of thing {@see payload()} must not do.
 */
final class IssueVirtualCard
{
    use BuildsConnexPayPayload;

    private const string ISSUE_CARD_PATH = '/api/v1/IssueCard';

    /**
     * @param  string  $incomingTransactionCode  The sale this card draws on, already resolved.
     */
    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly IssueCardCommand $command,
        private readonly ConnexPayHttpClientInterface $client,
        private readonly string $incomingTransactionCode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = [
            'MerchantGuid' => $this->settings->merchantGuid,
            'AmountLimit' => (float) $this->settings->formatAmount($this->command->amountLimit),
            'FirstName' => $this->command->firstName ?? 'N/A',
            'LastName' => $this->command->lastName ?? 'N/A',
            'PurchaseType' => $this->purchaseTypeCode(),
            'IncomingTransactionCode' => $this->incomingTransactionCode,
            'ReturnCardData' => true,
        ];

        $brand = $this->normalizedCardBrand();
        if ($brand !== null) {
            $data['CardBrand'] = $brand;
        }

        return $this->withIdentifiers($data, $this->command->clientUniqueId);
    }

    public function issue(): VirtualCardResult
    {
        try {
            $response = $this->client->post(self::ISSUE_CARD_PATH, $this->payload());
        } catch (GuzzleException $e) {
            // The transport message is stuffed into a card-shaped body rather than reported
            // directly, and that is deliberate preservation: the pre-conversion code built exactly
            // this shape and then fell through to the json fallback below, so the merchant read
            // `{"cardGuid":null,"status":"…"}`. Handing back `$e->getMessage()` would read far
            // better and is what this should eventually do — but the conversion does not change
            // what anyone reads.
            $response = ['cardGuid' => null, 'status' => $e->getMessage()];
        }

        $card = $response['card'] ?? [];
        $cardGuid = is_array($card) ? ($card['cardGuid'] ?? null) : null;

        if (empty($cardGuid)) {
            // Falling back to the raw body is deliberate: the Purchases API answers a refusal in
            // several shapes and an unrecognised one must still reach the caller as something to
            // read, rather than as a bare "issuance failed". Note that a top-level `status` is NOT
            // consulted — only `card.status` is — which is why the transport shape above lands
            // here as json.
            $message = $response['message']
                ?? $response['processorResponseMessage']
                ?? (is_array($card) ? $card['status'] ?? null : null)
                ?? json_encode($response);

            return VirtualCardResult::failed(is_string($message) && $message !== '' ? $message : 'Virtual card issuance failed.');
        }

        return VirtualCardResult::succeeded(
            cardGuid: (string) $cardGuid,
            // ConnexPay returns BOTH `expirationDate` (ISO datetime) and `expiration` (MMYY).
            // The MMYY one is what the downstream `card_expiration` column holds.
            cardNumber: $card['accountNumber'] ?? null,
            cvv: $card['securityCode'] ?? null,
            expirationDate: $card['expiration'] ?? null,
            status: $card['status'] ?? null,
        );
    }

    /**
     * ConnexPay expects a 2-digit MCC-style numeric `PurchaseType` ('01' =
     * Airline, '02' = HotelAndResort, …). Our domain
     * {@see \Techork\PaymentService\Gateway\ValueObject\CardSpendCategory} is converted to the
     * legacy {@see \Techork\PaymentService\Gateway\ValueObject\PurchaseType} via
     * {@see PurchaseTypeBridge::fromCategory}, then zero-padded.
     *
     * The category is an enum on the command now, so the "unknown category" refusal the old
     * request carried has nowhere left to fire: a value that is not a category cannot be handed
     * to one.
     */
    private function purchaseTypeCode(): string
    {
        $purchaseType = PurchaseTypeBridge::fromCategory($this->command->spendCategory);

        return str_pad((string) $purchaseType->value, 2, '0', STR_PAD_LEFT);
    }

    /**
     * ConnexPay expects PascalCase brand names ('Visa', 'Mastercard'). Other
     * networks from the domain {@see CardBrand} enum are not supported by
     * ConnexPay's virtual card issuer; null lets the issuer pick.
     */
    private function normalizedCardBrand(): ?string
    {
        $brand = $this->command->cardBrand;

        return match ($brand) {
            null => null,
            CardBrand::Visa => 'Visa',
            CardBrand::Mastercard => 'Mastercard',
            default => throw new InvalidArgumentException("Unsupported ConnexPay card brand: $brand->value"),
        };
    }
}
