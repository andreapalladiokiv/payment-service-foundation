<?php

declare(strict_types=1);

namespace Techork\PaymentService\Conferma;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Conferma\Exception\UnsupportedOperationException;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;

/**
 * ConfermaPay is an issuing-only gateway — it deploys virtual cards
 * funded from a pre-funded card pool tied to a `clientAccountValue`
 * (`cardPoolClientId` in the legacy schema). It does not acquire
 * payments and it does not tokenize cards.
 *
 * Acquiring/tokenization operations throw
 * {@see UnsupportedOperationException} — callers must route those
 * through an acquiring gateway (Stripe / Nuvei / ConnexPay).
 *
 * Configuration parameters (set via {@see initialize}):
 *  - `clientId`, `clientSecret`, `platformKey`: OAuth2 credentials
 *    (Basic header for client_id/secret, `scope` for platformKey)
 *  - `accessToken`: optional long-lived bearer that bypasses the
 *    OAuth2 exchange (e.g. for tests)
 *  - `environment`: `sandbox` (default) | `production`
 *  - `baseUrl`: optional API host override (production hostname is
 *    per-merchant — auth host is derived from `authUrl`)
 *  - `authUrl`: optional auth host override (sandbox default points
 *    at `assure.cert-confermapay.com`)
 *  - `clientAccountType`, `clientAccountValue`: VCN account identifier
 *    sent on every issuance (`type` is hardcoded `'CLIENTID'` in the
 *    legacy implementation; kept configurable here)
 *  - `defaultSupplierName`: fallback `supplier.name` for issuances
 *    that do not pass one explicitly
 *  - `paymentRangeDays`: default open-to-spend window length (default 30)
 */
final class ConfermaGateway extends AbstractGateway implements Gateway
{
    private ConfermaHttpClientInterface $client;

    public function getName(): string
    {
        return 'conferma';
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        // ConfermaPay does not maintain a remote customer registry —
        // virtual cards are issued ad-hoc and do not bind to a stored
        // customer. The contract method exists for cross-gateway
        // uniformity; the repository is intentionally ignored.
    }

    public function getDefaultParameters(): array
    {
        return [
            'clientId' => '',
            'clientSecret' => '',
            'platformKey' => '',
            'accessToken' => null,
            'environment' => 'sandbox',
            'baseUrl' => null,
            'authUrl' => null,
            'clientAccountType' => 'CLIENTID',
            'clientAccountValue' => '',
            'defaultSupplierName' => null,
            'paymentRangeDays' => 30,
        ];
    }

    public function getClientId(): string
    {
        return $this->getParameter('clientId') ?? '';
    }

    public function setClientId(string $value): static
    {
        return $this->setParameter('clientId', $value);
    }

    public function getClientSecret(): string
    {
        return $this->getParameter('clientSecret') ?? '';
    }

    public function setClientSecret(string $value): static
    {
        return $this->setParameter('clientSecret', $value);
    }

    public function getPlatformKey(): string
    {
        return $this->getParameter('platformKey') ?? '';
    }

    public function setPlatformKey(string $value): static
    {
        return $this->setParameter('platformKey', $value);
    }

    public function getAccessToken(): ?string
    {
        return $this->getParameter('accessToken');
    }

    public function setAccessToken(?string $value): static
    {
        return $this->setParameter('accessToken', $value);
    }

    public function getEnvironment(): string
    {
        return $this->getParameter('environment') ?? 'sandbox';
    }

    public function setEnvironment(string $value): static
    {
        return $this->setParameter('environment', $value);
    }

    public function getBaseUrl(): ?string
    {
        return $this->getParameter('baseUrl');
    }

    public function setBaseUrl(?string $value): static
    {
        return $this->setParameter('baseUrl', $value);
    }

    public function getAuthUrl(): ?string
    {
        return $this->getParameter('authUrl');
    }

    public function setAuthUrl(?string $value): static
    {
        return $this->setParameter('authUrl', $value);
    }

    public function getClientAccountType(): string
    {
        return $this->getParameter('clientAccountType') ?? 'CLIENTID';
    }

    public function setClientAccountType(string $value): static
    {
        return $this->setParameter('clientAccountType', $value);
    }

    public function getClientAccountValue(): string
    {
        return $this->getParameter('clientAccountValue') ?? '';
    }

    public function setClientAccountValue(string $value): static
    {
        return $this->setParameter('clientAccountValue', $value);
    }

    public function getDefaultSupplierName(): ?string
    {
        return $this->getParameter('defaultSupplierName');
    }

    public function setDefaultSupplierName(?string $value): static
    {
        return $this->setParameter('defaultSupplierName', $value);
    }

    public function getPaymentRangeDays(): int
    {
        return (int) ($this->getParameter('paymentRangeDays') ?? 30);
    }

    public function setPaymentRangeDays(int $value): static
    {
        return $this->setParameter('paymentRangeDays', $value);
    }

    public function initialize(array $parameters = []): static
    {
        // parent::initialize() drives Omnipay's Helper, which translates
        // snake_case keys (client_id, client_secret, platform_key …) into
        // the matching set*() calls. Reading our own getters afterwards is
        // the only way to see the same shape regardless of whether creds
        // come from the gateways table or a unit-test factory.
        parent::initialize($parameters);

        $environment = $this->getEnvironment();

        $this->client = new ConfermaClient(
            clientId: $this->getClientId(),
            clientSecret: $this->getClientSecret(),
            platformKey: $this->getPlatformKey(),
            apiBaseUrl: $this->getBaseUrl() ?? self::defaultApiUrl($environment),
            authBaseUrl: $this->getAuthUrl() ?? self::defaultAuthUrl($environment),
            accessToken: $this->getAccessToken(),
        );

        return $this;
    }

    public function setHttpClient(ConfermaHttpClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function issueVirtualCard(array $parameters = []): AbstractRequest
    {
        return $this->createConfermaRequest(IssueVirtualCardRequest::class, $parameters);
    }

    public function updateVirtualCard(array $parameters = []): AbstractRequest
    {
        return $this->createConfermaRequest(UpdateVirtualCardRequest::class, $parameters);
    }

    public function terminateVirtualCard(array $parameters = []): AbstractRequest
    {
        return $this->createConfermaRequest(TerminateCardRequest::class, $parameters);
    }

    public function purchase(array $parameters = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('purchase');
    }

    public function authorize(array $parameters = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('authorize');
    }

    public function capture(array $parameters = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('capture');
    }

    public function refund(array $parameters = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('refund');
    }

    public function void(array $parameters = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('void');
    }

    public function createCard(array $parameters = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('createCard');
    }

    public function createPaymentMethod(array $parameters = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('createPaymentMethod');
    }

    private static function defaultApiUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://api.confermapay.com'
            : ConfermaClient::SANDBOX_API_URL;
    }

    private static function defaultAuthUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://assure.confermapay.com'
            : ConfermaClient::SANDBOX_AUTH_URL;
    }

    /**
     * @param  class-string<AbstractRequest>  $class
     * @param  array<string, mixed>  $parameters
     */
    private function createConfermaRequest(string $class, array $parameters): AbstractRequest
    {
        return parent::createRequest($class, [
            ...$parameters,
            'confermaClient' => $this->client,
            'clientAccountType' => $this->getClientAccountType(),
            'clientAccountValue' => $this->getClientAccountValue(),
            'defaultSupplierName' => $this->getDefaultSupplierName(),
            'paymentRangeDays' => $this->getPaymentRangeDays(),
        ]);
    }
}
