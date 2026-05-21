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
 * Authorizes (holds) funds via ConnexPay without capturing.
 * Expects: money (Money), instrument (PaymentInstrument), gateway (Gateway).
 */
final class AuthorizeRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ConnexPayRequestParameters;
    use InstrumentParameters;

    public function getData(): array
    {
        $this->validate('money', 'instrument', 'gateway');

        /** @var Money $money */
        $money = $this->getParameter('money');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');
        $cardData = $instrument->accept($this);

        $data = [
            'DeviceGuid' => $this->getDeviceGuid(),
            'Amount' => (float) $this->formatMoney($money),
            'ConnexPayTransaction' => ['ExpectedPayments' => 1],
        ];

        if ($cardData !== null) {
            $data['Card'] = $cardData;
        }

        if ($this->getClientUniqueId() !== null) {
            $data['OrderNumber'] = $this->getClientUniqueId();
        }

        $billingAddress = $this->getParameter('billingAddress');
        if ($billingAddress !== null) {
            $customer = $this->formatBillingAddress($billingAddress);
            if ($customer !== []) {
                $data['Card']['Customer'] = $customer;
                $data['RiskData'] = array_filter([
                    'Email' => $customer['Email'] ?? null,
                ]);
            }
        }

        $threeDS = $this->getThreeDS();
        if ($threeDS !== null && isset($data['Card'])) {
            $data['Card']['ThreeDS'] = [
                'Cavv' => $threeDS->authenticationValue,
                'Version' => $threeDS->version?->value,
                'DirectoryServerTransactionID' => $threeDS->dsTransactionId,
                'AcsTransactionId' => $threeDS->acsTransactionId,
                'ECI' => $threeDS->eci?->value,
            ];
        }

        return $data;
    }

    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

        $data = [
            'CardNumber' => $card->number->getNumber($decrypter),
            'ExpirationDate' => $this->formatExpirationDate(
                $card->expiration->format('m'),
                $card->expiration->format('Y'),
            ),
        ];

        $cvv = $card->cvc->getCvc($decrypter);
        if ($cvv !== null && $cvv !== '') {
            $data['Cvv2'] = $cvv;
        }

        $name = (string) $card->holder;
        if ($name !== '') {
            $data['CardHolderName'] = $name;
        }

        return $data;
    }

    public function visitCash(Cash $cash): null
    {
        return null;
    }

    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No ConnexPay reference found for token {$token->id->toString()}.");

        return ['Guid' => $reference];
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        $reference = $this->getReferenceResolver()->find($gateway->getId(), $paymentMethod)
            ?? throw new RuntimeException("No ConnexPay reference found for payment method {$paymentMethod->id->toString()}.");

        return ['Guid' => $reference];
    }

    public function sendData($data): AuthorizeResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/authonlys', $data);

            return new AuthorizeResponse($this, $response);
        } catch (GuzzleException $e) {
            return new AuthorizeResponse($this, [
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
