<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Override;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use Techork\PaymentService\ConnexPay\Concern\FormatsThreeDS;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use GuzzleHttp\Exception\GuzzleException;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;

/**
 * Creates a sale via ConnexPay.
 * Expects: money (Money), instrument (PaymentInstrument), gateway (Gateway).
 *
 * Not final: {@see PartialCaptureRequest} reuses the sale build for the
 * void-then-resell partial capture flow.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
class PurchaseRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ConnexPayRequestParameters;
    use FormatsThreeDS;
    use InstrumentParameters;

    private const int EXPECTED_PAYMENTS_CARD = 1;

    private const int EXPECTED_PAYMENTS_CASH = 5;

    private const string HOSTED_PAGE_PATH = '/api/v1/HostedPaymentPageRequests';

    private const string HOSTED_EXPIRY_INTERVAL = 'PT4H';

    private const string HOSTED_TENDER = 'Credit';

    #[Override]
    public function getData(): array
    {
        $this->validate('money', 'instrument', 'gateway');

        /** @var Money $money */
        $money = $this->getParameter('money');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        $data = [
            'DeviceGuid' => $this->getDeviceGuid(),
            'Amount' => (float) $this->formatMoney($money),
        ];

        $statementDescription = $this->getStatementDescription();
        if ($statementDescription !== null && $statementDescription !== '') {
            $data['StatementDescription'] = $statementDescription;
        }

        $data = [...$data, ...$instrument->accept($this)];

        // A hosted payment builds a complete, differently-shaped body of its
        // own; none of the sale-only extras below apply to it and its
        // OrderNumber already sits inside `Sale`.
        if (isset($data['_hosted'])) {
            return ['_hosted' => true, '_hostedPayload' => $data['_hostedPayload']];
        }

        $threeDS = $this->formatThreeDS();
        if ($threeDS !== null && isset($data['Card']) && is_array($data['Card'])) {
            $data['Card']['ThreeDS'] = $threeDS;
        }

        $billingAddress = $this->getParameter('billingAddress');
        if ($billingAddress !== null && isset($data['Card'])) {
            $data['RiskData'] = $this->formatRiskData($billingAddress);
        }

        return $this->withCustomerId($this->withIdentifiers($data));
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

        $cardData = [
            'CardHolderName' => (string) $card->holder,
            'CardNumber' => $card->number->getNumber($decrypter),
            'ExpirationDate' => $this->formatExpirationDate(
                $card->expiration->format('m'),
                $card->expiration->format('Y'),
            ),
        ];

        $cvv = $card->cvc->getCvc($decrypter);
        if ($cvv !== null && $cvv !== '') {
            $cardData['Cvv2'] = $cvv;
        }

        return [
            'TenderType' => 'Credit',
            'Card' => $cardData,
            'ConnexPayTransaction' => ['ExpectedPayments' => self::EXPECTED_PAYMENTS_CARD],
        ];
    }

    #[Override]
    public function visitCash(Cash $cash): array
    {
        $billingAddress = $this->getParameter('billingAddress');

        $data = [
            'TenderType' => 'Cash',
            'ConnexPayTransaction' => ['ExpectedPayments' => self::EXPECTED_PAYMENTS_CASH],
        ];

        if ($billingAddress !== null) {
            $data['Customer'] = $this->formatCustomer($billingAddress);
        }

        return $data;
    }

    #[Override]
    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No ConnexPay reference found for token {$token->id->toString()}.");

        return [
            'TenderType' => 'Credit',
            'Card' => ['Guid' => $reference],
            'ConnexPayTransaction' => ['ExpectedPayments' => self::EXPECTED_PAYMENTS_CARD],
        ];
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        $reference = $this->getReferenceResolver()->find($gateway->getId(), $paymentMethod)
            ?? throw new RuntimeException("No ConnexPay reference found for payment method {$paymentMethod->id->toString()}.");

        return [
            'TenderType' => 'Credit',
            'Card' => ['Guid' => $reference],
            'ConnexPayTransaction' => ['ExpectedPayments' => self::EXPECTED_PAYMENTS_CARD],
        ];
    }

    #[Override]
    public function sendData($data): ConnexPayResponse
    {
        if (! empty($data['_hosted'])) {
            return $this->sendHostedData($data['_hostedPayload']);
        }

        try {
            $response = $this->getConnexPayClient()->post('/api/v1/sales', $data);

            return new PurchaseResponse($this, $response);
        } catch (GuzzleException $e) {
            return new PurchaseResponse($this, [
                'wasProcessed' => false,
                'guid' => null,
                'processorResponseMessage' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hosted-page flow: instead of a sale built from card data, ask ConnexPay
     * for a hosted-payment-page token and relay the buyer to their page. The
     * whole body is assembled here rather than in {@see sendHostedData()} so a
     * unit test can assert the exact payload without going near HTTP.
     *
     * Field placement is not what the public docs suggest, and was established
     * by probing the sandbox: `Amount` and `DeviceGuid` are validated on `Sale`
     * itself (inside `ConnexpayTransaction` they are ignored — an amount there
     * reads as 0 and trips the 0.5 minimum), `RiskData` is mandatory for card
     * tenders, and `ConnexpayTransaction` is required but only as a presence
     * check: an empty object is accepted. It carries `ExpectedPayments` anyway,
     * to match the sale payload.
     */
    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): array
    {
        /** @var Money $money */
        $money = $this->getParameter('money');

        $merchantName = $this->getMerchantName();
        $merchantName !== '' || throw new RuntimeException(
            'ConnexPay hosted payments require a `merchant_name` credential — it is the display name on the hosted page and the API rejects the request without it.',
        );

        $billingAddress = $this->getParameter('billingAddress');
        $billingAddress !== null || throw new RuntimeException(
            'ConnexPay hosted payments require a billing address: the API mandates Sale.RiskData for Credit, GooglePay and ApplePay tenders.',
        );

        // Same OrderNumber convention as every other ConnexPay call, and the
        // only thing the sale webhook can be correlated on — the response
        // carries no sale guid because the sale does not exist yet.
        $sale = $this->withOrderNumber([
            'Amount' => (float) $this->formatMoney($money),
            'DeviceGuid' => $this->getDeviceGuid(),
            'RiskData' => $this->formatRiskData($billingAddress),
            // Note the lower-case `p`, unlike `ConnexPayTransaction` on
            // /api/v1/sales above. This is the spelling the endpoint's own
            // validation error names and the one verified to work.
            'ConnexpayTransaction' => ['ExpectedPayments' => self::EXPECTED_PAYMENTS_CARD],
        ]);

        $payload = [
            'MerchantName' => $merchantName,
            'ResultRedirectUrl' => $hosted->successUrl,
            'CancelUrl' => $hosted->cancelUrl,
            'TenderTypeOptions' => [self::HOSTED_TENDER],
            // Sent explicitly because the default is the end of the following
            // day — far longer than a checkout should stay payable.
            'Expiration' => new DateTimeImmutable('now', new DateTimeZone('UTC'))
                ->add(new DateInterval(self::HOSTED_EXPIRY_INTERVAL))
                ->format('Y-m-d\TH:i:s'),
        ];

        $statementDescription = $this->getStatementDescription();
        if ($statementDescription !== null && $statementDescription !== '') {
            $payload['Description'] = $statementDescription;
        }

        $payload['Sale'] = $sale;

        return ['_hosted' => true, '_hostedPayload' => $payload];
    }

    private function sendHostedData(array $payload): HostedPaymentPageResponse
    {
        try {
            $response = $this->getConnexPayClient()->post(self::HOSTED_PAGE_PATH, $payload);

            return new HostedPaymentPageResponse($this, [
                ...$response,
                // The response does not echo it, so carry it across ourselves —
                // it is the reference the webhook will match.
                'orderNumber' => $payload['Sale']['OrderNumber'] ?? null,
            ]);
        } catch (GuzzleException $e) {
            return new HostedPaymentPageResponse($this, [
                'tempToken' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
