<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

use Override;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Revolut\Exception\UnsupportedOperationException;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Concern\HoldsInfrastructure;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

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
final class RevolutGateway implements Gateway
{
    use HoldsInfrastructure;

    private string $clientId = '';

    private string $privateKey = '';

    private string $refreshToken = '';

    private string $issuer = '';

    private ?string $baseUrl = null;

    /** @var ?list<string> */
    private ?array $accountIds = null;

    private ?string $product = null;

    private string $spendLimitPeriod = 'single';

    private ?int $validityDays = null;

    private bool $fetchSensitiveDetails = true;

    private RevolutHttpClientInterface $client;

    #[Override]
    public function getName(): string
    {
        return 'revolut';
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        // These cards are auto-issued with no holder at all (Revolut wants a
        // `product` code instead), so there is no payment customer to look up
        // — the contract method exists for cross-gateway uniformity and the
        // repository is intentionally ignored.
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function getIssuer(): string
    {
        return $this->issuer;
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /**
     * @return list<string>|null
     */
    public function getAccountIds(): ?array
    {
        return $this->accountIds;
    }

    public function getProduct(): ?string
    {
        return $this->product;
    }

    public function getSpendLimitPeriod(): string
    {
        return $this->spendLimitPeriod;
    }

    public function getValidityDays(): ?int
    {
        return $this->validityDays;
    }

    public function getFetchSensitiveDetails(): bool
    {
        return $this->fetchSensitiveDetails;
    }

    /**
     * Settings in, client out, once. `getResolvedBaseUrl()` reads the setting rather than a
     * property the client already baked, so the two cannot disagree.
     */
    #[Override]
    public function configure(GatewayInfrastructure $infrastructure): void
    {
        $this->infrastructure = $infrastructure;
        $this->clientId = $infrastructure->stringSetting('clientId');
        $this->privateKey = $infrastructure->stringSetting('privateKey');
        $this->refreshToken = $infrastructure->stringSetting('refreshToken');
        $this->issuer = $infrastructure->stringSetting('issuer');
        $this->product = $this->nullable($infrastructure->stringSetting('product'));
        $this->spendLimitPeriod = $infrastructure->stringSetting('spendLimitPeriod', 'single');
        $this->baseUrl = $this->nullable($infrastructure->stringSetting('baseUrl'));

        // Stored credentials hold either shape: the column was a single string before it was a
        // list, and a gateway whose row was never migrated has to keep loading.
        $accountIds = $infrastructure->setting('accountIds');
        $this->accountIds = match (true) {
            // Re-keyed and cast, because a credential column is untyped: what comes back is a
            // list of UUID strings only by convention, and the allow-list filter downstream
            // matches strings.
            is_array($accountIds) => array_values(array_map(strval(...), $accountIds)),
            $accountIds === '' || $accountIds === null => null,
            default => [(string) $accountIds],
        };

        $validityDays = $infrastructure->setting('validityDays');
        $this->validityDays = $validityDays === null ? null : (int) $validityDays;

        // Absent means yes: the card's own details are what a caller asked for a card to get.
        $this->fetchSensitiveDetails = (bool) ($infrastructure->setting('fetchSensitiveDetails') ?? true);

        $this->client = new RevolutClient(
            clientId: $this->clientId,
            privateKey: $this->privateKey,
            refreshToken: $this->refreshToken,
            issuer: $this->issuer,
            baseUrl: $this->getResolvedBaseUrl(),
        );
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
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

    #[Override]
    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult
    {
        return new IssueVirtualCard($this->client, $this->cardSettings())->issue($command);
    }

    #[Override]
    public function retryRefund(RefundCommand $command): GatewayResult
    {
        throw UnsupportedOperationException::operation('retryRefund');
    }

    #[Override]
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        return new UpdateVirtualCard($this->client, $this->cardSettings())->update($command);
    }

    #[Override]
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
    {
        return new TerminateCard($this->client)->terminate($command);
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        throw UnsupportedOperationException::operation('charge');
    }

    #[Override]
    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        throw UnsupportedOperationException::operation('authorize');
    }

    #[Override]
    public function capture(CaptureCommand $command): GatewayResult
    {
        throw UnsupportedOperationException::operation('capture');
    }

    #[Override]
    public function refund(RefundCommand $command): GatewayResult
    {
        throw UnsupportedOperationException::operation('refund');
    }

    #[Override]
    public function cancel(CancelCommand $command): GatewayResult
    {
        throw UnsupportedOperationException::operation('cancel');
    }

    #[Override]
    public function tokenize(VaultCommand $command): RegistrationResult
    {
        throw UnsupportedOperationException::operation('tokenize');
    }

    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        throw UnsupportedOperationException::operation('registerPaymentMethod');
    }

    /**
     * Swaps the HTTP client the configured gateway built. The only seam a test has for reaching
     * Revolut with a fake, now that construction happens in one pass.
     */
    public function setHttpClient(RevolutHttpClientInterface $client): void
    {
        $this->client = $client;
    }

    /**
     * What the deployment contributes to a card, as opposed to what the caller asked for.
     */
    private function cardSettings(): CardSettings
    {
        return new CardSettings(
            product: $this->product,
            accountIds: $this->accountIds,
            spendLimitPeriod: $this->spendLimitPeriod,
            validityDays: $this->validityDays,
            fetchSensitiveDetails: $this->fetchSensitiveDetails,
        );
    }

    #[Override]
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        throw UnsupportedOperationException::operation('authorizeRebilling');
    }
}
