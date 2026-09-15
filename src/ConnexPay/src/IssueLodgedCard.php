<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload;
use Techork\PaymentService\ConnexPay\Concern\LimitWindowMapper;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\ValueObject\PurchaseTypeBridge;

/**
 * Issues a lodged card — `POST /api/v1/IssueCard/LodgedCard` on the Purchases API.
 *
 * A lodged card is ConnexPay's balance-funded product: it draws on the merchant's cash balance
 * instead of on one cardholder sale, so no `IncomingTransactionCode` is sent and none can be — the
 * field is not in the endpoint's schema at all. That is why this cannot be folded into
 * {@see IssueVirtualCard} with a nullable code: the sale-funded endpoint declares
 * `IncomingTransactionCode` and refuses a request that reaches it without a sale to name.
 *
 * `LimitWindow` is the one field lodged adds and the only one it adds to `required`
 * (`["MerchantGuid", "FirstName", "LastName", "AmountLimit", "LimitWindow", "PurchaseType"]`
 * against the sale endpoint's same list minus the window). Everything else the two share.
 *
 * What is deliberately NOT sent: `UsageLimit`, `TerminateDate`, `MIDWhiteList`/`MIDBlackList` and
 * the country lists. The first two are documented on the sale-funded endpoint too — they are spend
 * controls the pre-bridge integration used to send and the bridge dropped, i.e. a standing
 * regression — and filing them under "lodged" would hide that. The MID lists are worse than
 * missing: the vendor's guide describes them and its update example sends them, but they appear in
 * no request schema, so their name on the issue call is a guess. This sends what the contract
 * states.
 *
 * Note the status code: the sale endpoint answers 201, this one answers 200.
 */
final class IssueLodgedCard
{
    use BuildsConnexPayPayload;

    private const string LODGED_CARD_PATH = '/api/v1/IssueCard/LodgedCard';

    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly IssueCardCommand $command,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when no limit window was named, or when the one named has no
     *                                  ConnexPay counterpart
     */
    public function payload(): array
    {
        $data = [
            'MerchantGuid' => $this->settings->merchantGuid,
            'AmountLimit' => (float) $this->settings->formatAmount($this->command->amountLimit),
            'FirstName' => $this->command->firstName ?? 'N/A',
            'LastName' => $this->command->lastName ?? 'N/A',
            'PurchaseType' => $this->purchaseTypeCode(),
            'LimitWindow' => $this->limitWindow(),
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
            $response = $this->client->post(self::LODGED_CARD_PATH, $this->payload());
        } catch (GuzzleException $e) {
            // Unlike the sale-funded sibling, the transport message is reported as itself. That
            // one stuffs it into a card-shaped body to preserve what merchants already read; this
            // endpoint has no callers yet, so there is nothing to preserve and no reason to start
            // by hiding the error.
            return VirtualCardResult::failed($e->getMessage());
        }

        $card = $response['card'] ?? [];
        $cardGuid = is_array($card) ? ($card['cardGuid'] ?? null) : null;

        if (empty($cardGuid)) {
            $message = $response['message']
                ?? $response['processorResponseMessage']
                ?? (is_array($card) ? $card['status'] ?? null : null)
                ?? json_encode($response);

            return VirtualCardResult::failed(is_string($message) && $message !== '' ? $message : 'Lodged card issuance failed.');
        }

        return VirtualCardResult::succeeded(
            cardGuid: (string) $cardGuid,
            // Same shape as the sale-funded response, field for field: `accountNumber`,
            // `securityCode`, and BOTH `expirationDate` (ISO) and `expiration` (MMYY). The MMYY
            // one is what the downstream `card_expiration` column holds.
            cardNumber: $card['accountNumber'] ?? null,
            cvv: $card['securityCode'] ?? null,
            expirationDate: $card['expiration'] ?? null,
            status: $card['status'] ?? null,
        );
    }

    /**
     * The window, which this endpoint requires and the gateway has no configured default for.
     *
     * Revolut can fall back to a deployment setting when a card names no window; ConnexPay has no
     * such setting, and the vendor lists `LimitWindow` in `required`. So the absence is refused
     * here rather than guessed at — a default period would decide how much money the card may
     * spend per span, which is not a default anyone can pick on the merchant's behalf.
     *
     * @throws InvalidArgumentException
     */
    private function limitWindow(): string
    {
        $window = $this->command->limitWindow
            ?? throw new InvalidArgumentException(
                'ConnexPay lodged cards require a limit window; the request named none and the gateway has no default.',
            );

        return LimitWindowMapper::fromWindow($window);
    }

    /**
     * @see IssueVirtualCard::purchaseTypeCode() — same 2-digit zero-padded code, same bridge.
     */
    private function purchaseTypeCode(): string
    {
        $purchaseType = PurchaseTypeBridge::fromCategory($this->command->spendCategory);

        return str_pad((string) $purchaseType->value, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @see IssueVirtualCard::normalizedCardBrand() — ConnexPay's PascalCase brand names.
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
