<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Exception\GuzzleException;
use Override;
use RuntimeException;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\ConnexPay\Concern\BuildsConnexPayPayload;
use Techork\PaymentService\ConnexPay\Concern\FormatsThreeDS;
use Techork\PaymentService\ConnexPay\Concern\MapsConnexPayOutcome;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;

/**
 * Takes a payment outright — `POST /api/v1/sales`.
 *
 * Three parts, and only the middle one touches the network: {@see payload()} turns the command
 * into the body, {@see charge()} sends it, and the mapping to an {@see AuthorizationResult}
 * happens where the provider's answer is still in hand. There is no request object to construct
 * and send, and no response object wrapping the payload for something else to re-read — Omnipay
 * needed both because a request was a bag with a lifecycle and a response had to be interrogated
 * through a common interface. Neither is true here.
 *
 * Final, unlike the request it replaces. {@see PartialCapture} reuses the sale build for the
 * void-then-resell flow by COMPOSING this rather than extending it — inheritance is what let the
 * hosted branch below reach a capture at all, and the old subclass had to override
 * `visitHostedPayment()` to shut a door it should never have been handed.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class Purchase implements PaymentInstrumentVisitor
{
    use BuildsConnexPayPayload;
    use FormatsThreeDS;
    use MapsConnexPayOutcome;

    private const int EXPECTED_PAYMENTS_CARD = 1;

    private const int EXPECTED_PAYMENTS_CASH = 5;

    /**
     * Public because {@see PartialCapture} posts the very same sale, built by the very same
     * {@see payload()}, after voiding the authorization it replaces.
     */
    public const string SALES_PATH = '/api/v1/sales';

    private const string HOSTED_PAGE_PATH = '/api/v1/HostedPaymentPageRequests';

    private const string HOSTED_PAGE_SEGMENT = '/HostedPaymentPage/';

    private const string HOSTED_EXPIRY_INTERVAL = 'PT4H';

    private const string HOSTED_TENDER = 'Credit';

    public function __construct(
        private readonly ConnexPaySettings $settings,
        private readonly PlacementCommand $command,
        private readonly GatewayInfrastructure $infrastructure,
        private readonly ConnexPayHttpClientInterface $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $instrument = $this->command->instrument;

        // A hosted payment builds a complete, differently-shaped body of its
        // own; none of the sale-only extras below apply to it and its
        // OrderNumber already sits inside `Sale`.
        if ($instrument instanceof HostedPayment) {
            return $this->visitHostedPayment($instrument);
        }

        $data = [
            'DeviceGuid' => $this->settings->deviceGuid,
            'Amount' => (float) $this->settings->formatAmount($this->command->amount),
        ];

        $statementDescription = $this->command->statementDescription;
        if ($statementDescription !== null && $statementDescription !== '') {
            $data['StatementDescription'] = $statementDescription;
        }

        $data = [...$data, ...$instrument->accept($this)];

        $threeDS = $this->formatThreeDS($this->command->threeDS);
        if ($threeDS !== null && isset($data['Card']) && is_array($data['Card'])) {
            $data['Card']['ThreeDS'] = $threeDS;
        }

        $customer = $this->command->customer;
        if ($customer !== null && isset($data['Card'])) {
            $data['RiskData'] = $this->formatRiskData($customer);
        }

        return $this->withCustomerId(
            $this->withIdentifiers($data, $this->command->clientUniqueId),
            $customer,
        );
    }

    public function charge(): AuthorizationResult
    {
        $payload = $this->payload();

        if ($this->command->instrument instanceof HostedPayment) {
            return $this->requestHostedPage($payload);
        }

        try {
            return $this->authorization($this->client->post(self::SALES_PATH, $payload));
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

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitCash(Cash $cash): array
    {
        $data = [
            'TenderType' => 'Cash',
            'ConnexPayTransaction' => ['ExpectedPayments' => self::EXPECTED_PAYMENTS_CASH],
        ];

        $customer = $this->command->customer;
        if ($customer !== null) {
            $data['Customer'] = $this->formatCustomer($customer);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitToken(Token $token): array
    {
        return $this->storedCard($this->storedReference($token, "token {$token->id->toString()}"));
    }

    /**
     * Refused when nobody has claimed the card, and that is the whole reason this is one
     * method rather than two.
     *
     * There was a `visitAttachedPaymentMethod()` beside a `visitPaymentMethod()` that only
     * threw, which made "payable" something a signature carried. Attached is a state of a
     * payment method — see {@see PaymentMethod::isAttached()} — so the branch became a guard.
     * What it costs is that the guarantee is now checked rather than typed; what it buys is
     * one credential with one identity everywhere downstream of here.
     */
    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): array
    {
        // A stored card is charged to somebody, and an unattached one names nobody. The
        // payer is not derivable from the card: what used to answer was the address the
        // payment method carried, so a card was charged to whoever it was billed to.
        $paymentMethod->isAttached() || throw UnsupportedInstrument::needsAttachedCustomer('connexpay', 'charge', $paymentMethod);

        return $this->storedCard(
            $this->storedReference($paymentMethod, "payment method {$paymentMethod->id->toString()}"),
        );
    }

    /**
     * Hosted-page flow: instead of a sale built from card data, ask ConnexPay
     * for a hosted-payment-page token and relay the buyer to their page. The
     * whole body is assembled here rather than in {@see requestHostedPage()} so a
     * unit test can assert the exact payload without going near HTTP.
     *
     * Field placement is not what the public docs suggest, and was established
     * by probing the sandbox: `Amount` and `DeviceGuid` are validated on `Sale`
     * itself (inside `ConnexpayTransaction` they are ignored — an amount there
     * reads as 0 and trips the 0.5 minimum), `RiskData` is mandatory for card
     * tenders, and `ConnexpayTransaction` is required but only as a presence
     * check: an empty object is accepted. It carries `ExpectedPayments` anyway,
     * to match the sale payload.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): array
    {
        $merchantName = $this->settings->merchantName;
        $merchantName !== '' || throw new RuntimeException(
            'ConnexPay hosted payments require a `merchant_name` credential — it is the display name on the hosted page and the API rejects the request without it.',
        );

        $customer = $this->command->customer;
        $customer !== null || throw new RuntimeException(
            'ConnexPay hosted payments require a customer: the API mandates Sale.RiskData for Credit, GooglePay and ApplePay tenders, and that block names the payer as well as their address.',
        );

        // Same OrderNumber convention as every other ConnexPay call, and the
        // only thing the sale webhook can be correlated on — the response
        // carries no sale guid because the sale does not exist yet.
        $sale = $this->withOrderNumber([
            'Amount' => (float) $this->settings->formatAmount($this->command->amount),
            'DeviceGuid' => $this->settings->deviceGuid,
            'RiskData' => $this->formatRiskData($customer),
            // Note the lower-case `p`, unlike `ConnexPayTransaction` on
            // /api/v1/sales above. This is the spelling the endpoint's own
            // validation error names and the one verified to work.
            'ConnexpayTransaction' => ['ExpectedPayments' => self::EXPECTED_PAYMENTS_CARD],
        ], $this->command->clientUniqueId);

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

        $statementDescription = $this->command->statementDescription;
        if ($statementDescription !== null && $statementDescription !== '') {
            $payload['Description'] = $statementDescription;
        }

        $payload['Sale'] = $sale;

        return $payload;
    }

    /**
     * A hosted page is never a completed payment: the endpoint hands back a short-lived token,
     * the buyer pays on ConnexPay's own page, and the sale is announced later by the
     * `sale.card.auth.*` webhook. So this reports a redirect challenge and never a success — the
     * intent parks in `RequiresAction` until the webhook lands.
     *
     * The reference is our own `OrderNumber` (the payment intent id), not a sale guid: verified
     * against the sandbox, the response carries no guid because the sale does not exist until
     * someone pays, and there is no endpoint to read the request back afterwards. The webhook
     * handler correlates on `orderNumber` for exactly that reason — which is why the value is
     * carried across from the request rather than read back out of the answer.
     *
     * @param  array<string, mixed>  $payload
     */
    private function requestHostedPage(array $payload): AuthorizationResult
    {
        $reference = $payload['Sale']['OrderNumber'] ?? null;
        $reference = is_string($reference) && $reference !== '' ? $reference : null;

        try {
            $response = $this->client->post(self::HOSTED_PAGE_PATH, $payload);
        } catch (GuzzleException $e) {
            return AuthorizationResult::failed($e->getMessage());
        }

        $token = $response['tempToken'] ?? null;
        $token = is_string($token) && $token !== '' ? $token : null;
        $host = self::hostedPageHost($response);

        if ($token !== null && $reference !== null && $host !== null) {
            return AuthorizationResult::requiresAction($reference, new RedirectChallenge(
                transactionId: $reference,
                url: $host.self::HOSTED_PAGE_SEGMENT.$token,
                formFields: [],
            ))->withMetadata(['opening_transaction_reference' => $reference]);
        }

        // A token with nowhere to send the buyer is worse than a plain failure,
        // because the challenge silently goes missing and the caller falls back
        // to reporting an unsuccessful response. Name it.
        if ($token !== null && $host === null) {
            return AuthorizationResult::failed(
                'ConnexPay returned a hosted-page token but no otherUrl to derive the page host from.',
            );
        }

        if ($token !== null && $reference === null) {
            return AuthorizationResult::failed(
                'ConnexPay accepted the hosted-page request without an OrderNumber, leaving the sale webhook nothing to correlate on.',
            );
        }

        // A more specific sentence would read better here — "ConnexPay returned no hosted-page
        // token" — but this is what the merchant was told before the conversion, and the
        // conversion does not change what anyone reads.
        $message = $response['message'] ?? null;

        return AuthorizationResult::failed(
            is_string($message) && $message !== ''
                ? $message
                : self::responseMessage($response) ?? 'Gateway returned an unsuccessful response.',
        );
    }

    /**
     * Scheme + host of the hosted page, read back from `otherUrl` — ConnexPay
     * names its own result page there (`…/HostedPaymentResult`), served from the
     * same host as the payment page. Reading it beats hardcoding: only the
     * sandbox host is documented anywhere, so the production one would be a
     * guess, and a wrong guess sends buyers into the void.
     *
     * Only https passes: the host is where the buyer is redirected with the
     * tempToken in the URL, and a payment page over an unencrypted transport
     * hands that token — and everything the buyer types on the page — to
     * whoever sits between. A host ConnexPay has not named as https reads as
     * no host at all, and the payment fails with the named reason.
     *
     * @param  array<string, mixed>  $response
     */
    private static function hostedPageHost(array $response): ?string
    {
        $other = $response['otherUrl'] ?? null;

        if (! is_string($other) || $other === '') {
            return null;
        }

        $parts = parse_url($other);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        return $scheme === 'https' && $host !== null ? 'https://'.$host : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedCard(string $reference): array
    {
        return [
            'TenderType' => 'Credit',
            'Card' => ['Guid' => $reference],
            'ConnexPayTransaction' => ['ExpectedPayments' => self::EXPECTED_PAYMENTS_CARD],
        ];
    }

    private function storedReference(Token|PaymentMethod $instrument, string $describedAs): string
    {
        return $this->infrastructure->instruments->find($this->infrastructure->credential->getId(), $instrument)
            ?? throw new RuntimeException("No ConnexPay reference found for {$describedAs}.");
    }
}
