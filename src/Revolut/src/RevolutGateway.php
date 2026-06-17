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
 *  - `accessToken`: Bearer token for the Business API. Revolut tokens are
 *    short-lived (40 min) and refreshed out-of-band via the JWT
 *    client-assertion flow; the application layer owns that lifecycle and
 *    injects the currently-valid token (same pattern the legacy stack used
 *    for ConnexPay / ConfermaPay).
 *  - `baseUrl`: optional API host override. There is NO Revolut Sandbox for
 *    virtual cards — every card operation targets Production
 *    (https://b2b.revolut.com), so this exists only for tests / an outbound
 *    proxy, not as an environment switch.
 *  - `holderId`: UUID of the Revolut team member who holds issued cards
 *    (the `holder_id` on create). Required to issue a card.
 *  - `accountId`: optional UUID of the account the card draws from
 *    (the `accounts` allow-list on create).
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
            'accessToken' => '',
            'baseUrl' => null,
            'holderId' => null,
            'accountId' => null,
            'spendLimitPeriod' => 'single',
            'validityDays' => null,
            'fetchSensitiveDetails' => true,
        ];
    }

    public function getAccessToken(): string
    {
        return $this->getParameter('accessToken') ?? '';
    }

    public function setAccessToken(string $value): static
    {
        return $this->setParameter('accessToken', $value);
    }

    public function getBaseUrl(): ?string
    {
        return $this->getParameter('baseUrl');
    }

    public function setBaseUrl(?string $value): static
    {
        return $this->setParameter('baseUrl', $value);
    }

    public function getHolderId(): ?string
    {
        return $this->getParameter('holderId');
    }

    public function setHolderId(?string $value): static
    {
        return $this->setParameter('holderId', $value);
    }

    public function getAccountId(): ?string
    {
        return $this->getParameter('accountId');
    }

    public function setAccountId(?string $value): static
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
        // snake_case keys (access_token, holder_id, account_id …) into the
        // matching set*() calls. Reading our own getters afterwards is the
        // only way to see the same shape regardless of whether creds come
        // from the gateways table or a unit-test factory.
        parent::initialize($parameters);

        $this->client = new RevolutClient(
            accessToken: $this->getAccessToken(),
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
            'holderId' => $parameters['holderId'] ?? $this->getHolderId(),
            'accountId' => $parameters['accountId'] ?? $this->getAccountId(),
            'spendLimitPeriod' => $parameters['spendLimitPeriod'] ?? $this->getSpendLimitPeriod(),
            'validityDays' => $parameters['validityDays'] ?? $this->getValidityDays(),
            'fetchSensitiveDetails' => $parameters['fetchSensitiveDetails'] ?? $this->getFetchSensitiveDetails(),
        ]);
    }
}
