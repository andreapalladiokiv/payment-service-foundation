<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;

final class ConnexPayGateway extends AbstractGateway implements Gateway
{
    private ConnexPayClient $client;

    private ConnexPayPurchasesClient $purchasesClient;

    private ?CustomerRepository $customerRepository = null;

    public function getName(): string
    {
        return 'connexpay';
    }

    public function setCustomerRepository(CustomerRepository $repository): void
    {
        $this->customerRepository = $repository;
    }

    public function getDefaultParameters(): array
    {
        return [
            'username' => '',
            'password' => '',
            'deviceGuid' => '',
            'merchantGuid' => '',
            'environment' => 'sandbox',
        ];
    }

    public function getUsername(): string
    {
        return $this->getParameter('username') ?? '';
    }

    public function setUsername(string $value): static
    {
        return $this->setParameter('username', $value);
    }

    public function getPassword(): string
    {
        return $this->getParameter('password') ?? '';
    }

    public function setPassword(string $value): static
    {
        return $this->setParameter('password', $value);
    }

    public function getDeviceGuid(): string
    {
        return $this->getParameter('deviceGuid') ?? '';
    }

    public function setDeviceGuid(string $value): static
    {
        return $this->setParameter('deviceGuid', $value);
    }

    public function getMerchantGuid(): string
    {
        return $this->getParameter('merchantGuid') ?? '';
    }

    public function setMerchantGuid(string $value): static
    {
        return $this->setParameter('merchantGuid', $value);
    }

    public function getEnvironment(): string
    {
        return $this->getParameter('environment') ?? 'sandbox';
    }

    public function setEnvironment(string $value): static
    {
        return $this->setParameter('environment', $value);
    }

    public function initialize(array $parameters = []): static
    {
        // parent::initialize() drives Omnipay's Helper, which translates
        // snake_case keys (device_guid, merchant_guid) into set*() calls.
        // Reading our own getters afterwards is the only way to see the
        // same shape regardless of whether creds come from the gateways
        // table or a unit-test factory.
        parent::initialize($parameters);

        $this->client = new ConnexPayClient(
            username: $this->getUsername(),
            password: $this->getPassword(),
            environment: $this->getEnvironment(),
        );

        $this->purchasesClient = new ConnexPayPurchasesClient(
            username: $this->getUsername(),
            password: $this->getPassword(),
            environment: $this->getEnvironment(),
        );

        return $this;
    }

    public function createCard(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(CreateCardRequest::class, $parameters);
    }

    public function createPaymentMethod(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(CreatePaymentMethodRequest::class, $parameters);
    }

    public function purchase(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(PurchaseRequest::class, $parameters);
    }

    public function authorize(array $parameters = []): AbstractRequest
    {
        // ConnexPay's /authonlys endpoint doesn't accept cash; auths against a
        // cash tender must go through /sales instead. Match the legacy
        // acquirer's behavior of transparently routing Cash to charge.
        if (($parameters['instrument'] ?? null) instanceof Cash) {
            return $this->purchase($parameters);
        }

        return $this->createRequest(AuthorizeRequest::class, $parameters);
    }

    /**
     * ConnexPay can only capture the full authorized amount
     * (https://docs.connexpay.com/docs/auth-and-capture). For a smaller
     * amount the documented procedure — and what the legacy integration
     * did — is void the AuthOnly and run a fresh sale with the original
     * instrument, which {@see PartialCaptureRequest} implements. Requires
     * `authorizedAmount` + `instrument` in the parameters; without them a
     * partial request can't be detected and the full hold would be
     * captured silently.
     */
    public function capture(array $parameters = []): AbstractRequest
    {
        $money = $parameters['money'] ?? null;
        $authorized = $parameters['authorizedAmount'] ?? null;

        if ($money !== null && $authorized !== null && $money->greaterThan($authorized)) {
            throw new \InvalidArgumentException('Capture amount exceeds the authorized amount.');
        }

        if ($money !== null && $authorized !== null && $money->lessThan($authorized)) {
            $instrument = $parameters['instrument'] ?? null;

            if ($instrument === null) {
                throw new \InvalidArgumentException(
                    'ConnexPay cannot capture a partial amount without the original instrument '
                    .'(full-auth void + fresh sale is required).',
                );
            }

            if ($instrument instanceof PaymentMethod && ! isset($parameters['billingAddress'])) {
                $parameters['billingAddress'] = $instrument->billingAddress;
            }

            return $this->createRequest(PartialCaptureRequest::class, $parameters);
        }

        return $this->createRequest(CaptureRequest::class, $parameters);
    }

    public function refund(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(RefundRequest::class, $parameters);
    }

    public function retryRefund(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(ReturnRetryRequest::class, $parameters);
    }

    public function void(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(VoidRequest::class, $parameters);
    }

    public function issueVirtualCard(array $parameters = []): AbstractRequest
    {
        // Prefer the code persisted with the sale / capture response
        // (passed down by the router from gateway_references.metadata) —
        // Search/Sales is the fallback, not the source of truth.
        $incomingTransactionCode = $parameters['incomingTransactionCode'] ?? null;

        if ($incomingTransactionCode === null || $incomingTransactionCode === '') {
            $transactionReference = $parameters['transactionReference'] ?? null;
            $incomingTransactionCode = $transactionReference !== null
                ? $this->resolveIncomingTransactionCode($transactionReference, $parameters['clientUniqueId'] ?? null)
                : null;
        }

        return parent::createRequest(IssueVirtualCardRequest::class, [
            ...$parameters,
            'connexPayClient' => $this->purchasesClient,
            'merchantGuid' => $this->getMerchantGuid(),
            'incomingTransactionCode' => $incomingTransactionCode,
            'cardBrand' => $parameters['cardBrand'] ?? null,
        ]);
    }

    public function updateVirtualCard(array $parameters = []): AbstractRequest
    {
        return parent::createRequest(UpdateVirtualCardRequest::class, [
            ...$parameters,
            'connexPayClient' => $this->purchasesClient,
        ]);
    }

    public function terminateVirtualCard(array $parameters = []): AbstractRequest
    {
        return parent::createRequest(TerminateCardRequest::class, [
            ...$parameters,
            'connexPayClient' => $this->purchasesClient,
        ]);
    }

    /**
     * Search/Sales silently ignores any body filter outside its documented
     * list — `SaleGuid` / `Guid` / `IncomingTransactionCode` are NOT honored
     * and the endpoint just returns sales pages for the merchant. The only
     * usable narrowing filter we have is `OrderNumber` (sent on the original
     * sale as the clientUniqueId), so filter by it when available and always
     * match the row's `guid` against `$saleGuid` client-side while paging.
     */
    private function resolveIncomingTransactionCode(string $saleGuid, ?string $orderNumber = null): string
    {
        $filters = ['MerchantGuid' => $this->getMerchantGuid()];

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

        throw new \RuntimeException("Could not resolve IncomingTransactionCode for sale GUID: {$saleGuid}");
    }

    protected function createRequest($class, array $parameters): AbstractRequest
    {
        return parent::createRequest($class, [
            ...$parameters,
            'connexPayClient' => $this->client,
            'deviceGuid' => $this->getDeviceGuid(),
            'customerRepository' => $this->customerRepository,
        ]);
    }
}
