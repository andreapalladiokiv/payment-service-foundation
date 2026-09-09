<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload;
use Techork\PaymentService\ConnexPay\Concern\FormatsThreeDS;
use Techork\PaymentService\ConnexPay\Concern\MapsConnexPayOutcome;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;

/**
 * Holds funds without taking them — `POST /api/v1/authonlys`.
 *
 * Takes either placement command as an argument, because the body is the same one either way: ConnexPay's
 * auth-only carries no field for a series position, so a rebilling authorization differs from an
 * ordinary one only in what the caller stated, not in what is sent. Naming both command types
 * here rather than flattening the rebilling one into a placement keeps the anchor visible for the
 * day the wire contract grows a slot for it — see the stored-credential notes on
 * {@see RebillingCommand}.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class Authorize implements PaymentInstrumentVisitor
{
    use BuildsConnexPayPayload;
    use FormatsThreeDS;
    use MapsConnexPayOutcome;

    private const string AUTH_ONLYS_PATH = '/api/v1/authonlys';

    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly PlacementCommand|RebillingCommand $command,
        private readonly GatewayInfrastructure $infrastructure,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = [
            'DeviceGuid' => $this->settings->deviceGuid,
            'Amount' => (float) $this->settings->formatAmount($this->command->amount),
        ];

        $statementDescription = $this->command->statementDescription;
        if ($statementDescription !== null && $statementDescription !== '') {
            $data['StatementDescription'] = $statementDescription;
        }

        $data['TenderType'] = 'Credit';
        $data['Card'] = $this->command->instrument->accept($this);

        $threeDS = $this->formatThreeDS($this->command->threeDS);
        if ($threeDS !== null) {
            $data['Card']['ThreeDS'] = $threeDS;
        }

        $customer = $this->command->customer;
        if ($customer !== null) {
            $data['RiskData'] = $this->formatRiskData($customer);
        }

        return $this->withCustomerId(
            $this->withIdentifiers($data, $this->command->clientUniqueId),
            $customer,
        );
    }

    public function authorize(): AuthorizationResult
    {
        try {
            return $this->authorization($this->client->post(self::AUTH_ONLYS_PATH, $this->payload()));
        } catch (GuzzleException $e) {
            return AuthorizationResult::failed($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitCreditCard(CreditCard $card): array
    {
        $decrypter = $this->infrastructure->decrypter;

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
        // Cash is a purchase-only product at ConnexPay; /authonlys has no form of it.
        throw UnsupportedInstrument::forGateway('connexpay', 'authorize', $cash);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitToken(Token $token): array
    {
        return ['Guid' => $this->storedReference($token, "token {$token->id->toString()}")];
    }

    /**
     * Refused: a stored card is charged to somebody, and a bare payment method names nobody.
     *
     * What this used to do is now {@see visitAttachedPaymentMethod()}, unchanged apart from
     * reaching the instrument through the customer that holds it. The refusal is the change:
     * the payer used to come off the address the payment method carried, so a card was charged
     * to whoever it happened to be billed to.
     */
    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw UnsupportedInstrument::needsAttachedCustomer('connexpay', 'authorize', $paymentMethod);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitAttachedPaymentMethod(AttachedPaymentMethod $attached): array
    {
        return ['Guid' => $this->storedReference($attached->paymentMethod, "payment method {$attached->paymentMethod->id->toString()}")];
    }

    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): never
    {
        throw UnsupportedInstrument::forGateway('connexpay', 'authorize', $hosted);
    }

    private function storedReference(Token|PaymentMethod $instrument, string $describedAs): string
    {
        return $this->infrastructure->instruments->find($this->infrastructure->credential->getId(), $instrument)
            ?? throw new RuntimeException("No ConnexPay reference found for {$describedAs}.");
    }
}
