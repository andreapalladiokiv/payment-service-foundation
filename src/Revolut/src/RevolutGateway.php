<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Revolut\Exception\UnsupportedOperationException;

/**
 * Revolut Business is an issuing-only gateway — it deploys virtual cards
 * via the Business API (https://developer.revolut.com/docs/business/cards)
 * and does not acquire payments or tokenize cards. Acquiring/tokenization
 * operations throw {@see UnsupportedOperationException}; callers route
 * those through an acquiring gateway (Stripe / Nuvei / ConnexPay).
 *
 * Configuration parameters (set via {@see initialize}):
 *  - `clientId`, `privateKey`, `refreshToken`, `issuer`: the Business API
 *    OAuth 2.0 credentials. {@see RevolutClient} signs a JWT client assertion
 *    with `privateKey` and exchanges `refreshToken` for a short-lived access
 *    token on demand — the SDK owns the token lifecycle rather than expecting
 *    a pre-minted bearer token. `issuer` is the domain of the OAuth
 *    redirect URL registered with Revolut (the JWT `iss` claim).
 *  - `baseUrl`: optional API host override. There is NO Revolut Sandbox for
 *    virtual cards — every card operation targets Production
 *    (https://b2b.revolut.com), so this exists only for tests / an outbound
 *    proxy, not as an environment switch.
 *  - `accountId`: optional list of account UUIDs the card draws from
 *    (the `accounts` allow-list on create). Omit to use the business default.
 *  - `spendLimitPeriod`: which spend-limit bucket the deployment amount
 *    maps to (`single` default, or `day`/`week`/`month`/…).
 *  - `validityDays`: optional open-to-spend window; when > 0 the card is
 *    created with a terminating `spending_period`.
 *  - `fetchSensitiveDetails`: whether issuance follows up with
 *    `GET /cards/{id}/sensitive-details` to surface PAN + CVV (default true).
 */
final class RevolutGateway extends AbstractGateway implements Gateway
{
    private RevolutHttpClientInterface $client;

    public function getName(): string
    {
        return 'revolut';
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        // Revolut cards are issued against a team-member holder, not a
        // stored payment customer — the contract method exists for
        // cross-gateway uniformity and the repository is intentionally
        // ignored.
    }

    public function getDefaultParameters(): array
    {
        return [
            'clientId' => '',
            'privateKey' => '',
            'refreshToken' => '',
            'issuer' => '',
            'baseUrl' => null,
            'accountId' => null,
            'spendLimitPeriod' => 'single',
            'validityDays' => null,
            'fetchSensitiveDetails' => true,
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

    public function getPrivateKey(): string
    {
        return $this->getParameter('privateKey') ?? '';
    }

    public function setPrivateKey(string $value): static
    {
        return $this->setParameter('privateKey', $value);
    }

    public function getRefreshToken(): string
    {
        return $this->getParameter('refreshToken') ?? '';
    }

    public function setRefreshToken(string $value): static
    {
        return $this->setParameter('refreshToken', $value);
    }

    public function getIssuer(): string
    {
        return $this->getParameter('issuer') ?? '';
    }

    public function setIssuer(string $value): static
    {
        return $this->setParameter('issuer', $value);
    }

    public function getBaseUrl(): ?string
    {
        return $this->getParameter('baseUrl');
    }

    public function setBaseUrl(?string $value): static
    {
        return $this->setParameter('baseUrl', $value);
    }

    /**
     * @return list<string>|null
     */
    public function getAccountId(): ?array
    {
        return $this->getParameter('accountId');
    }

    /**
     * @param  list<string>|null  $value
     */
    public function setAccountId(?array $value): static
    {
        return $this->setParameter('accountId', $value);
    }

    public function getSpendLimitPeriod(): string
    {
        return $this->getParameter('spendLimitPeriod') ?? 'single';
    }

    public function setSpendLimitPeriod(string $value): static
    {
        return $this->setParameter('spendLimitPeriod', $value);
    }

    public function getValidityDays(): ?int
    {
        $days = $this->getParameter('validityDays');

        return $days === null ? null : (int) $days;
    }

    public function setValidityDays(?int $value): static
    {
        return $this->setParameter('validityDays', $value);
    }

    public function getFetchSensitiveDetails(): bool
    {
        $value = $this->getParameter('fetchSensitiveDetails');

        return $value === null ? true : (bool) $value;
    }

    public function setFetchSensitiveDetails(bool $value): static
    {
        return $this->setParameter('fetchSensitiveDetails', $value);
    }

    public function initialize(array $parameters = []): static
    {
        // parent::initialize() drives Omnipay's Helper, which translates
        // snake_case keys (client_id, private_key, account_id …) into the
        // matching set*() calls. Reading our own getters afterwards is the
        // only way to see the same shape regardless of whether creds come
        // from the gateways table or a unit-test factory.
        parent::initialize($parameters);

        $this->client = new RevolutClient(
            clientId: $this->getClientId(),
            privateKey: $this->getPrivateKey(),
            refreshToken: $this->getRefreshToken(),
            issuer: $this->getIssuer(),
            baseUrl: $this->getResolvedBaseUrl(),
        );

        return $this;
    }

    /**
     * The API host the client talks to: an explicit `baseUrl` override when
     * set, otherwise the production host. Revolut has no virtual-card
     * Sandbox, so there is no environment-based alternative.
     */
    public function getResolvedBaseUrl(): string
    {
        return $this->getBaseUrl() ?? RevolutClient::PRODUCTION_BASE_URL;
    }

    public function setHttpClient(RevolutHttpClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function issueVirtualCard(array $parameters = []): AbstractRequest
    {
        return $this->createRevolutRequest(IssueVirtualCardRequest::class, $parameters);
    }

    public function updateVirtualCard(array $parameters = []): AbstractRequest
    {
        return $this->createRevolutRequest(UpdateVirtualCardRequest::class, $parameters);
    }

    public function terminateVirtualCard(array $parameters = []): AbstractRequest
    {
        return $this->createRevolutRequest(TerminateCardRequest::class, $parameters);
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

    /**
     * @param  class-string<AbstractRequest>  $class
     * @param  array<string, mixed>  $parameters
     */
    private function createRevolutRequest(string $class, array $parameters): AbstractRequest
    {
        return parent::createRequest($class, [
            ...$parameters,
            'revolutClient' => $this->client,
            'accountId' => $parameters['accountId'] ?? $this->getAccountId(),
            'spendLimitPeriod' => $parameters['spendLimitPeriod'] ?? $this->getSpendLimitPeriod(),
            'validityDays' => $parameters['validityDays'] ?? $this->getValidityDays(),
            'fetchSensitiveDetails' => $parameters['fetchSensitiveDetails'] ?? $this->getFetchSensitiveDetails(),
        ]);
    }
}
