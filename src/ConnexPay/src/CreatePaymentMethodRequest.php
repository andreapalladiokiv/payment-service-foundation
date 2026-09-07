<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Override;
use Techork\PaymentService\Gateway\Concern\InstrumentParameters;
use Techork\PaymentService\ConnexPay\Concern\ConnexPayRequestParameters;
use Techork\PaymentService\ConnexPay\Concern\FormatsThreeDS;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use GuzzleHttp\Exception\GuzzleException;
use Omnipay\Common\Message\AbstractRequest;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
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
 * **This is where a ConnexPay customer is born, and it is the only place.**
 * ConnexPay publishes no endpoint that creates one from an identity alone —
 * every one of them takes a card — so `PaymentGatewayInterface::registerCustomer`
 * is refused for this gateway and the registration does that job instead. The
 * guid comes back on {@see CreatePaymentMethodResponse::getCustomerReference()},
 * reaches the caller as `RegistrationResult::$customerReference`, and pairs with
 * the `customerId` the caller already passed to this same call — so the caller
 * has both halves and can record them in `GatewayCustomerRepository`.
 *
 * **What that made of `billingAddress` until now.** `Card.Customer` was built
 * from the address alone, so the customer ConnexPay created was whoever the
 * card happened to be billed to, and `CustomerIdentity` — the name, email and
 * phone the merchant actually recorded — never reached it. That is the
 * address-derived provider customer `docs/customer-domain-plan` exists to end,
 * removed for Nuvei and Stripe in F5 and surviving here because nothing in the
 * plan expected a customer object on this gateway at all. `customerIdentity`
 * now names the person and the address keeps naming the address; see
 * {@see ConnexPayRequestParameters::formatCustomer()} for why both are needed
 * rather than one replacing the other.
 *
 * Expects: instrument (PaymentInstrument), gateway (Gateway). Optional:
 * customerIdentity (the person on `Card.Customer`), billingAddress (the
 * address on it, and the AVS payload).
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class CreatePaymentMethodRequest extends AbstractRequest implements PaymentInstrumentVisitor
{
    use ConnexPayRequestParameters;
    use FormatsThreeDS;
    use InstrumentParameters;

    #[Override]
    public function getData(): array
    {
        $this->validate('instrument', 'gateway');

        /** @var PaymentInstrument $instrument */
        $instrument = $this->getParameter('instrument');

        $card = $instrument->accept($this);

        $billingAddress = $this->getParameter('billingAddress');
        $identity = $this->getCustomerIdentity();

        // Either one is enough to be worth sending: an identity with no address still tells
        // ConnexPay who this customer is, and an address with no identity is the AVS payload plus
        // the fallback person. Only both absent leaves nothing to say.
        if ($billingAddress !== null || $identity !== null) {
            $card['Customer'] = $this->formatCustomer($billingAddress, $identity);
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

    #[Override]
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

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw new RuntimeException('Cash cannot be stored as a payment method.');
    }

    #[Override]
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

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw new RuntimeException('PaymentMethod cannot be re-stored as a payment method.');
    }

    #[Override]
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

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('connexpay', 'createPaymentMethod', $hosted);
    }

    /**
     * Declared here and not on {@see ConnexPayRequestParameters}, deliberately.
     *
     * Omnipay applies an option only where a matching setter exists, so putting this on the shared
     * trait would have every ConnexPay request accept `customerIdentity` and all but this one
     * ignore it — the "injected dependency nothing reads" that let a dead customer path look wired
     * for as long as it did. Narrow instead: the request that builds a customer is the request
     * that can be told who it is.
     */
    public function setCustomerIdentity(?CustomerIdentity $value): self
    {
        return $this->setParameter('customerIdentity', $value);
    }

    public function getCustomerIdentity(): ?CustomerIdentity
    {
        $identity = $this->getParameter('customerIdentity');

        return $identity instanceof CustomerIdentity ? $identity : null;
    }
}
