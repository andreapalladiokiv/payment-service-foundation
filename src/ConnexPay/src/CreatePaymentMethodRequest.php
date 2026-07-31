<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use GuzzleHttp\Exception\GuzzleException;
use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;

/**
 * Registers a payment instrument as a reusable ConnexPay payment method via
 * the Verify endpoint ($0 verification) — for tokens this re-verifies the
 * stored card GUID together with the cardholder's Customer data, which is
 * what makes ConnexPay create / link the customer on their side and return
 * fresh AVS / CVV signals. The legacy integration did the same on
 * `addPaymentMethod`; a local pass-through (what this request used to be)
 * skips customer creation entirely.
 *
 * Expects: instrument (PaymentInstrument), gateway (Gateway). Optional:
 * billingAddress (becomes `Card.Customer`).
 */
final class CreatePaymentMethodRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ConnexPayRequestParameters;
    use InstrumentParameters;

    public function getData(): array
    {
        $this->validate('instrument', 'gateway');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        $card = $instrument->accept($this);

        $billingAddress = $this->getParameter('billingAddress');
        if ($billingAddress !== null) {
            $card['Customer'] = $this->formatCustomer($billingAddress);
        }

        // A registration that was authenticated must carry the result through,
        // or the 3DS step-up is performed and then discarded.
        $threeDS = $this->formatThreeDS();
        if ($threeDS !== null) {
            $card['ThreeDS'] = $threeDS;
        }

        return [
            'DeviceGuid' => $this->getDeviceGuid(),
            'Card' => $card,
        ];
    }

    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->getDecrypter();

        $data = [
            'CardHolderName' => (string) $card->holder,
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

        return $data;
    }

    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('Cash cannot be stored as a payment method.');
    }

    public function visitToken(Token $token): array
    {
        /** @var GatewayCredential $gateway */
        $gateway = $this->getParameter('gateway');

        $reference = $this->getReferenceResolver()->find($gateway->getId(), $token)
            ?? throw new RuntimeException("No ConnexPay reference found for token {$token->id->toString()}.");

        $data = ['Guid' => $reference];

        if ($token->instrument instanceof CreditCard) {
            $cvv = $token->instrument->cvc->getCvc($this->getDecrypter());
            if ($cvv !== null && $cvv !== '') {
                $data['Cvv2'] = $cvv;
            }
        }

        return $data;
    }

    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod cannot be re-stored as a payment method.');
    }

    public function sendData($data): CreatePaymentMethodResponse
    {
        try {
            $response = $this->getConnexPayClient()->post('/api/v1/verify', $data);

            return new CreatePaymentMethodResponse($this, [
                'wasProcessed' => ($response['wasProcessed'] ?? false) === true,
                'guid' => $response['card']['guid'] ?? null,
                'customerGuid' => $response['card']['customer']['guid'] ?? null,
                'status' => $response['status'] ?? null,
                'processorResponseMessage' => $response['processorResponseMessage'] ?? null,
                'addressVerificationCode' => $response['addressVerificationCode'] ?? null,
                'cvvVerificationCode' => $response['cvvVerificationCode'] ?? null,
            ]);
        } catch (GuzzleException $e) {
            return new CreatePaymentMethodResponse($this, [
                'wasProcessed' => false,
                'guid' => null,
                'processorResponseMessage' => $e->getMessage(),
            ]);
        }
    }

    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('connexpay', 'createPaymentMethod', $hosted);
    }
}
