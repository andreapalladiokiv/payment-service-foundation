<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook;

use GuzzleHttp\Exception\GuzzleException;
use Money\Currency;
use Money\Money;
use Override;
use Psr\Log\LoggerInterface;
use Techork\PaymentService\ConnexPay\ConnexPayClient;
use Techork\PaymentService\ConnexPay\ConnexPayPurchasesClient;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Default {@see ServiceFeeFetcher} implementation — calls
 * Search/Sales (Sales API) and Search/Purchases (Purchases API) to pull
 * a single row by guid and extract `serviceFee`. Webhook payloads do
 * not carry the fee — only the search endpoints expose it — so this
 * fetcher does the single-row lookup at webhook handle time, which is
 * why we don't need a daily reconciliation cron.
 *
 * **Field shape is undocumented** in ConnexPay's public API reference.
 * We read `serviceFee` defensively (top-level + a few likely paths)
 * and log a warning on absence so operators can verify on a sandbox
 * sample without touching code. When the real shape is confirmed,
 * narrow {@see extractFee} to the canonical path.
 *
 * Returns `null` to mean "no fee available right now" — caller treats
 * this as Skipped (webhook delivery layer retries the same event later).
 */
final readonly class HttpServiceFeeFetcher implements ServiceFeeFetcher
{
    public function __construct(
        private GatewayCredentialRepository $credentialRepository,
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function fetchSaleFee(GatewayId $gatewayId, string $saleGuid): ?Money
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $credentials = $credential->getCredentials();

        $client = new ConnexPayClient(
            username: $credentials['username'] ?? '',
            password: $credentials['password'] ?? '',
            environment: $credentials['environment'] ?? 'sandbox',
        );

        try {
            $response = $client->post('/api/v1/Search/Sales/false/1/1', [
                'MerchantGuid' => $credentials['merchant_guid'] ?? '',
                'SaleGuid' => $saleGuid,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('ConnexPay serviceFee fetch (sale) failed', [
                'gateway_id' => $gatewayId->toString(),
                'sale_guid' => $saleGuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $row = $response['searchResultDTO'][0] ?? null;
        if (! is_array($row)) {
            return null;
        }

        return $this->extractFee($row, 'sale', $saleGuid, $gatewayId);
    }

    #[Override]
    public function fetchPurchaseFee(GatewayId $gatewayId, string $cardGuid): ?Money
    {
        $credential = $this->credentialRepository->findOrFail($gatewayId);
        $credentials = $credential->getCredentials();

        $client = new ConnexPayPurchasesClient(
            username: $credentials['username'] ?? '',
            password: $credentials['password'] ?? '',
            environment: $credentials['environment'] ?? 'sandbox',
        );

        try {
            // Endpoint shape is the documented mirror of Search/Sales on
            // the Purchases API. Confirm against sandbox if/when the
            // first real settlement webhook fires.
            $response = $client->post('/api/v1/Search/Purchases/false/1/1', [
                'MerchantGuid' => $credentials['merchant_guid'] ?? '',
                'CardGuid' => $cardGuid,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('ConnexPay serviceFee fetch (purchase) failed', [
                'gateway_id' => $gatewayId->toString(),
                'card_guid' => $cardGuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $row = $response['searchResultDTO'][0] ?? null;
        if (! is_array($row)) {
            return null;
        }

        return $this->extractFee($row, 'purchase', $cardGuid, $gatewayId);
    }

    /**
     * Defensive extraction — `serviceFee` is documented (per the
     * `search-sales` reference page) but sample payload isn't published.
     * Try a few realistic locations; warn on miss.
     */
    private function extractFee(array $row, string $kind, string $reference, GatewayId $gatewayId): ?Money
    {
        $rawAmount = $row['serviceFee']
            ?? $row['ServiceFee']
            ?? $row['fee']
            ?? null;

        if ($rawAmount === null) {
            $this->logger->warning('ConnexPay serviceFee absent on row — verify field name on sandbox', [
                'kind' => $kind,
                'gateway_id' => $gatewayId->toString(),
                'reference' => $reference,
                'available_keys' => array_keys($row),
            ]);

            return null;
        }

        // ConnexPay Sales API quotes amounts in major-currency units
        // (decimal), matching how IssueVirtualCard etc. send them.
        $cents = (int) round((float) $rawAmount * 100.0);
        if ($cents <= 0) {
            return null;
        }

        $currencyCode = strtoupper((string) ($row['currency'] ?? $row['Currency'] ?? 'USD'));

        // A fee quoted in no currency is not a fee. Falling back to the 'USD' default above
        // only covers an absent key, not a present-but-empty one.
        if ($currencyCode === '') {
            return null;
        }

        return new Money($cents, new Currency($currencyCode));
    }
}
