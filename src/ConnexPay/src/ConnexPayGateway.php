<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use InvalidArgumentException;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Concern\HoldsInfrastructure;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * ConnexPay, as two APIs behind one driver: the sales API acquires payments and the Purchases
 * API issues the virtual cards those payments fund. They have separate hosts and separate
 * bearer tokens minted from the same username and password, which is why there are two clients
 * here and why each operation is handed the one it talks to.
 *
 * Every role method builds its operation and asks it for the answer, in one expression. There are
 * no per-operation accessors: they existed only so a test could reach a `payload()` without making
 * the call, and a test does not need them — {@see ConnexPaySettings} is a public value object, so
 * anything that wants a payload can build the operation itself. A seam for tests does not belong
 * in the gateway's public API when the thing it reaches is already reachable.
 */
final class ConnexPayGateway implements Gateway
{
    use HoldsInfrastructure;

    private string $username = '';

    private string $password = '';

    private ConnexPaySettings $settings;

    private ConnexPayHttpClientInterface $client;

    private ConnexPayHttpClientInterface $purchasesClient;

    #[Override]
    public function getName(): string
    {
        return 'connexpay';
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        // ConnexPay's customer is created by `/api/v1/verify` and read back out of
        // `card.customer.guid`, so nothing here has to look one up — the contract method exists
        // for cross-gateway uniformity and the repository is intentionally ignored.
    }

    /**
     * Settings in, both clients out, once.
     *
     * The clients bake the base URL from the environment, which is why this used to be run twice:
     * infrastructure defaults were applied after the first `initialize()`, and a client already
     * built from the tenant's `environment` would otherwise keep talking to it. Merging the
     * settings before configuring makes the second pass unnecessary and the stale client
     * impossible.
     */
    #[Override]
    public function configure(GatewayInfrastructure $infrastructure): void
    {
        $this->infrastructure = $infrastructure;
        $this->username = $infrastructure->stringSetting('username');
        $this->password = $infrastructure->stringSetting('password');

        $this->settings = new ConnexPaySettings(
            deviceGuid: $infrastructure->stringSetting('deviceGuid'),
            merchantGuid: $infrastructure->stringSetting('merchantGuid'),
            merchantName: $infrastructure->stringSetting('merchantName'),
            accountCurrency: $infrastructure->stringSetting('accountCurrency'),
            environment: $infrastructure->stringSetting('environment', 'sandbox'),
        );

        $this->client = new ConnexPayClient(
            username: $this->username,
            password: $this->password,
            environment: $this->settings->environment,
        );

        $this->purchasesClient = new ConnexPayPurchasesClient(
            username: $this->username,
            password: $this->password,
            environment: $this->settings->environment,
        );
    }

    /**
     * What the deployment contributes to a ConnexPay request, as opposed to what the caller asked
     * for. Public because it is the whole of what an operation needs besides a command and a
     * client, so anything that wants to build one — a test, a probe — can.
     */
    public function settings(): ConnexPaySettings
    {
        return $this->settings;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getDeviceGuid(): string
    {
        return $this->settings->deviceGuid;
    }

    public function getMerchantGuid(): string
    {
        return $this->settings->merchantGuid;
    }

    public function getMerchantName(): string
    {
        return $this->settings->merchantName;
    }

    /**
     * The configured account currency, empty meaning USD — the gateway's own reading, which is
     * deliberately NOT {@see ConnexPaySettings::acquiringCurrency()}. That one upper-cases, trims
     * and refuses a currency ConnexPay cannot acquire in, because it guards an amount about to be
     * billed. This one only reports what the credential says, which is what it has always
     * reported and all any caller asks it for.
     */
    public function getAccountCurrency(): string
    {
        return $this->settings->accountCurrency ?: 'USD';
    }

    public function getEnvironment(): string
    {
        return $this->settings->environment;
    }

    /**
     * Swaps the clients the configured gateway built — the sales API first, the Purchases API
     * second when it differs. The only seam a test has for reaching ConnexPay with a fake, now
     * that construction happens in one pass.
     */
    public function setHttpClient(ConnexPayHttpClientInterface $client, ?ConnexPayHttpClientInterface $purchasesClient = null): void
    {
        $this->client = $client;
        $this->purchasesClient = $purchasesClient ?? $client;
    }

    #[Override]
    public function tokenize(VaultCommand $command): RegistrationResult
    {
        return new CreateCard($this->settings, $command, $this->infrastructure(), $this->client)->tokenize();
    }

    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        return new CreatePaymentMethod($this->settings, $command, $this->infrastructure(), $this->client)->register();
    }

    /**
     * Refused for capability, not for absence — the distinction that matters most on this
     * gateway, because getting it wrong is what left an address-derived provider customer alive
     * inside {@see registerPaymentMethod()} for as long as it did.
     *
     * ConnexPay HAS a customer object. `/api/v1/verify` creates one out of what it is handed and
     * returns it as `card.customer.guid`, which is what
     * {@see \Techork\PaymentService\Gateway\Contract\RegistrationResult::$customerReference}
     * carries back. What it has no route for is creating one from an identity alone: the v1
     * surface is `/verify`, `/token`, `/sales`, `/authonlys`, `/void` and `/returns`, and every
     * one of those that can make a customer takes a card. So the customer here comes into
     * existence by registering their payment method, and there is no call this method could
     * make.
     *
     * Marked, so the stack rethrows rather than folding it into a decline: ConnexPay refusing a
     * customer it was never asked about would be a lie about the provider.
     */
    #[Override]
    public function registerCustomer(RegisterCustomerCommand $command): RegistrationResult
    {
        throw UnsupportedOperation::forGateway(
            'connexpay',
            'registerCustomer',
            'ConnexPay has a customer object but no endpoint that creates one without a card; register the payment method instead and keep the reference it hands back.',
        );
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        return new Purchase($this->settings, $command, $this->infrastructure(), $this->client)->charge();
    }

    /**
     * ConnexPay's /authonlys has no cash tender, so an auth against a cash payment has to run as a
     * sale instead — transparently, the way the legacy acquirer did it.
     */
    #[Override]
    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        return $command->instrument instanceof Cash
            ? $this->charge($command)
            : new Authorize($this->settings, $command, $this->infrastructure(), $this->client)->authorize();
    }

    /**
     * The same provider call as {@see authorize()}, with the series position added — which is why
     * the two share an operation class and differ only in what the command puts in it. It is still
     * a separate operation, because whether a payment belongs to a series is the caller's to state
     * and no field of an ordinary authorization implies it.
     *
     * No Cash detour here, unlike {@see authorize()}: a cash tender has no stored credential to
     * put in a series, so routing one to /sales would open a chain nothing could continue.
     */
    #[Override]
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        return new Authorize($this->settings, $command, $this->infrastructure(), $this->client)->authorize();
    }

    /**
     * ConnexPay has no native partial capture: taking less than was authorized means voiding the
     * authorization and running a fresh sale, which needs the original instrument. Choosing
     * between the two is this provider's business and stays here — every other gateway ignores
     * both `authorizedAmount` and `instrument` on a capture, and this is the only driver that
     * answers one role with two different provider calls.
     */
    #[Override]
    public function capture(CaptureCommand $command): GatewayResult
    {
        $money = $command->amount;
        $authorized = $command->authorizedAmount;

        if ($authorized !== null && $money->greaterThan($authorized)) {
            throw new InvalidArgumentException('Capture amount exceeds the authorized amount.');
        }

        if ($authorized !== null && $money->lessThan($authorized)) {
            // Refused before anything is built, so a capture that cannot be run at all is rejected
            // while the authorization is still intact.
            $command->instrument !== null || throw new InvalidArgumentException(
                'ConnexPay cannot capture a partial amount without the original instrument '
                .'(full-auth void + fresh sale is required).',
            );

            return new PartialCapture($this->settings, $command, $this->infrastructure(), $this->client)->capture();
        }

        return new Capture($this->settings, $command, $this->client)->capture();
    }

    #[Override]
    public function refund(RefundCommand $command): GatewayResult
    {
        return new Refund($this->settings, $command, $this->client)->refund();
    }

    #[Override]
    public function retryRefund(RefundCommand $command): GatewayResult
    {
        return new ReturnRetry($this->settings, $command, $this->infrastructure(), $this->client)->retry();
    }

    #[Override]
    public function cancel(CancelCommand $command): GatewayResult
    {
        return new VoidTransaction($this->settings, $command, $this->client)->cancel();
    }

    #[Override]
    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult
    {
        // Prefer the code the caller carried in — persisted with the sale or capture response —
        // over asking Search/Sales, which is the fallback rather than the source of truth. It is
        // resolved here rather than inside the operation because it comes off the OTHER API.
        $incomingTransactionCode = $command->incomingTransactionCode;

        if ($incomingTransactionCode === null || $incomingTransactionCode === '') {
            $incomingTransactionCode = $this->resolveIncomingTransactionCode(
                $command->transactionReference,
                $command->clientUniqueId,
            );
        }

        return new IssueVirtualCard(
            $this->cardSettings(),
            $command,
            $this->purchasesClient,
            $incomingTransactionCode,
        )->issue();
    }

    #[Override]
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        return new UpdateVirtualCard($this->cardSettings(), $command, $this->purchasesClient)->update();
    }

    #[Override]
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
    {
        return new TerminateCard($command, $this->purchasesClient)->terminate();
    }

    /**
     * The settings the Purchases API operations get, with the account currency BLANKED — which
     * resolves to USD, so a card limit in any other currency is refused.
     *
     * That is a preserved defect, not a decision. Before the conversion the two card-issuing
     * methods reached their requests through Omnipay's `AbstractGateway::createRequest()`
     * directly, skipping this driver's own override — and the override was the only thing that
     * put `accountCurrency` into the request's parameter bag, because `configure()` reads the
     * credential into a typed property and never calls `setParameter()`. So the guard on the
     * Purchases API has always compared against USD whatever the merchant is provisioned in.
     * Verified by running the pre-conversion code: a GBP account issuing a GBP card limit threw
     * "provisioned in USD but the amount is GBP".
     *
     * It is left alone because the conversion is not the place to change which amounts a merchant
     * can issue a card for. Handing these operations {@see settings()} instead is the one-line
     * fix, and it wants its own change with its own reasoning: acquiring is limited to four
     * currencies and issuing supports roughly thirty, so the acquiring guard is arguably the
     * wrong check here in either direction.
     */
    private function cardSettings(): ConnexPaySettings
    {
        return new ConnexPaySettings(
            deviceGuid: $this->settings->deviceGuid,
            merchantGuid: $this->settings->merchantGuid,
            merchantName: $this->settings->merchantName,
            accountCurrency: '',
            environment: $this->settings->environment,
        );
    }

    /**
     * Search/Sales silently ignores any body filter outside its documented
     * list — `SaleGuid` / `Guid` / `IncomingTransactionCode` are NOT honored
     * and the endpoint just returns sales pages for the merchant. The only
     * usable narrowing filter we have is `OrderNumber` (sent on the original
     * sale as the clientUniqueId), so filter by it when available and always
     * match the row's `guid` against `$saleGuid` client-side while paging.
     *
     * It lives on the gateway rather than in {@see IssueVirtualCard} because it talks to the
     * OTHER API — the sale is on the sales host, the card is issued on the Purchases one.
     */
    private function resolveIncomingTransactionCode(string $saleGuid, ?string $orderNumber = null): string
    {
        $filters = ['MerchantGuid' => $this->settings->merchantGuid];

        if ($orderNumber !== null && $orderNumber !== '') {
            $filters['OrderNumber'] = preg_replace('/:(?:capture|cancel)$/', '', $orderNumber);
        }

        $page = 1;
        $pageTotal = null;
        // Safety cap — without it a bad filter would page through every sale
        // on the merchant.
        $maxPages = 20;

        do {
            $result = $this->client->post("/api/v1/Search/Sales/false/{$page}/100", $filters);

            foreach ($result['searchResultDTO'] ?? [] as $row) {
                if (is_array($row) && ($row['guid'] ?? null) === $saleGuid && ! empty($row['incomingTransactionCode'])) {
                    return $row['incomingTransactionCode'];
                }
            }

            $pageTotal ??= (int) ($result['pageTotal'] ?? 0);
            $page++;
        } while ($page <= $pageTotal && $page <= $maxPages);

        throw new RuntimeException("Could not resolve IncomingTransactionCode for sale GUID: {$saleGuid}");
    }
}
