<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use GuzzleHttp\Exception\GuzzleException;
use Money\Money;
use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\Token;

/**
 * Creates a sale via ConnexPay.
 * Expects: money (Money), instrument (PaymentInstrument), gateway (Gateway).
 *
 * Not final: {@see PartialCaptureRequest} reuses the sale build for the
 * void-then-resell partial capture flow.
 */
class PurchaseRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ConnexPayRequestParameters;
    use InstrumentParameters;

    private const int EXPECTED_PAYMENTS_CARD = 1;

    private const int EXPECTED_PAYMENTS_CASH = 5;

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

        $threeDS = $this->getThreeDS();
        if ($threeDS !== null && isset($data['Card'])) {
            $data['Card']['ThreeDS'] = [
                'Cavv' => $threeDS->authenticationValue,
                'Version' => $threeDS->version?->value,
                'DirectoryServerTransactionID' => (string) $threeDS->dsTransactionId,
                'AcsTransactionId' => (string) $threeDS->acsTransactionId,
                'ECI' => $threeDS->eci?->value,
            ];
        }

        $billingAddress = $this->getParameter('billingAddress');
        if ($billingAddress !== null && isset($data['Card'])) {
            $data['RiskData'] = $this->formatRiskData($billingAddress);
        }

        return $this->withOrderNumber($data);
    }

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

    public function sendData($data): PurchaseResponse
    {
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

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw new \RuntimeException('Gateway does not support hosted-payment instruments.');
    }
}
