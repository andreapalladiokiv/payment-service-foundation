<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
use Override;
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
 *  - `accountIds`: optional list of account UUIDs the card draws from
 *    (the `accounts` allow-list on create). Omit to use the business default.
 *  - `product`: the Revolut card product/program code the card is issued
 *    under. Required by the create-card API for auto-issued virtual cards
 *    (no holder / no contacts) — this integration's case.
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

    #[Override]
    public function getName(): string
    {
        return 'revolut';
    }


    #[Override]
    public function getDefaultParameters(): array
    {
        return [
            'clientId' => '',
            'privateKey' => '',
            'refreshToken' => '',
            'issuer' => '',
            'baseUrl' => null,
            'accountIds' => null,
            'product' => null,
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
    public function getAccountIds(): ?array
    {
        return $this->getParameter('accountIds');
    }

    /**
     * Accepts a list of account UUIDs. Tolerates a bare string too, so a
     * gateway whose credentials still hold a single string initialises without
     * a TypeError.
     *
     * @param  list<string>|string|null  $value
     */
    public function setAccountIds(array|string|null $value): static
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : [$value];
        }

        return $this->setParameter('accountIds', $value);
    }

    public function getProduct(): ?string
    {
        return $this->getParameter('product');
    }

    public function setProduct(?string $value): static
    {
        return $this->setParameter('product', $value);
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

        return $value === null || $value;
    }

    public function setFetchSensitiveDetails(bool $value): static
    {
        return $this->setParameter('fetchSensitiveDetails', $value);
    }

    #[Override]
    public function initialize(array $parameters = []): static
    {
        // parent::initialize() drives Omnipay's Helper, which translates
        // snake_case keys (client_id, private_key, account_ids …) into the
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

    #[Override]
    public function issueVirtualCard(array $options = []): AbstractRequest
    {
        return $this->createRevolutRequest(IssueVirtualCardRequest::class, $options);
    }

    #[Override]
    public function retryRefund(array $options = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('retryRefund');
    }

    #[Override]
    public function updateVirtualCard(array $options = []): AbstractRequest
    {
        return $this->createRevolutRequest(UpdateVirtualCardRequest::class, $options);
    }

    #[Override]
    public function terminateVirtualCard(array $options = []): AbstractRequest
    {
        return $this->createRevolutRequest(TerminateCardRequest::class, $options);
    }

    public function purchase(array $options = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('purchase');
    }

    public function authorize(array $options = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('authorize');
    }

    public function capture(array $options = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('capture');
    }

    public function refund(array $options = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('refund');
    }

    #[Override]
    public function void(array $options = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('void');
    }

    public function createCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperationException::operation('createCard');
    }

    #[Override]
    public function createPaymentMethod(array $options = []): AbstractRequest
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
            'accountIds' => $parameters['accountIds'] ?? $this->getAccountIds(),
            'product' => $parameters['product'] ?? $this->getProduct(),
            'spendLimitPeriod' => $parameters['spendLimitPeriod'] ?? $this->getSpendLimitPeriod(),
            'validityDays' => $parameters['validityDays'] ?? $this->getValidityDays(),
            'fetchSensitiveDetails' => $parameters['fetchSensitiveDetails'] ?? $this->getFetchSensitiveDetails(),
        ]);
    }
}
