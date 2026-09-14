<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Webhook\Handler;

use ArrayObject;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Exception\ParserException;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use Override;
use Techork\PaymentService\ConnexPay\ConnexPaySettings;
use Techork\PaymentService\ConnexPay\Webhook\SaleCorrelation;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * ConnexPay `sale.card.auth.approved` — the sale was taken.
 *
 * This handler is what unparks a hosted payment. Asking ConnexPay for a
 * hosted page returns only a token, so the intent is left at
 * `RequiresAction` with a {@see \Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge}
 * and nothing else ever touches it: the buyer pays on ConnexPay's page, the
 * acquirer takes the money, and until this event arrives the aggregate still
 * reads as awaiting an action nobody will take. Recording the success is what
 * calls `confirmChallenge` and moves it to `Charged`.
 *
 * The same event also arrives for a card-data sale, where the saga already
 * applied the outcome inline — there the recorder reports `Skipped`, which is
 * the duplicate case and not an error.
 *
 * Correlation is by {@see SaleCorrelation}: a hosted sale has no guid we could
 * have stored, so the fallback to `orderNumber` (the payment intent id we sent
 * as `clientUniqueId`) is the path that matters here. The reference recorded is
 * the sale guid, because that is the id family a later refund or void carries.
 *
 * Skipped if `guid` is missing — without it there is no reference to record;
 * delayed if our intent has not been observed yet, so the retry can pick it up.
 *
 * @implements WebhookEventHandler<ArrayObject>
 */
final readonly class SaleApprovedHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewaySuccessRecorder $recorder,
        private GatewayCredentialRepository $credentials,
    ) {}

    #[Override]
    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var ArrayObject $event */
        $payload = $event->getArrayCopy();

        $saleGuid = (string) ($payload['guid'] ?? $payload['Guid'] ?? '');
        if ($saleGuid === '') {
            return HandlerOutcome::Skipped;
        }

        $correlation = SaleCorrelation::resolve($this->resolver, $gatewayId, $saleGuid, $payload);
        if (! $correlation->found()) {
            return HandlerOutcome::Delay;
        }

        $amount = $this->amount($payload, $gatewayId);
        if ($amount === null) {
            // Not Skipped: a sale we cannot hand to the recorder is a paid
            // payment this handler exists to resolve, so it asks for a retry
            // rather than marking the delivery done. A body that is malformed
            // for good ends as a failed call, which is visible; a silent skip
            // is the defect, not the fallback.
            return HandlerOutcome::Delay;
        }

        return match ($this->recorder->onGatewaySuccess(
            $gatewayId,
            $correlation->paymentIntentId,
            $saleGuid,
            $amount,
        )) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }

    /**
     * The sale amount, in the account's acquiring currency.
     *
     * `amount` is documented as a decimal — "Amount of the sale transaction" —
     * and the samples are major units (`1.15`, `20`, `999999.99`), the same
     * scale the Sales API takes and gives back, and the scale the v1 API has no
     * currency field to qualify. So the currency is not on the payload and
     * cannot be: it is the one the merchant account is provisioned in, which is
     * a deployment fact we already hold. It comes from the credential through
     * {@see ConnexPaySettings::acquiringCurrency()}, the same accessor the
     * outbound side uses, so a misconfigured account currency fails loudly here
     * instead of rebranding the amount.
     *
     * Null when the payload carries no usable amount; the caller decides what
     * that means. Blank counts as no amount — the parser reads `''` as a
     * zero-value Money, and a sale of nothing is not what this event is.
     *
     * @param  array<string, mixed>  $payload
     */
    private function amount(array $payload, GatewayId $gatewayId): ?Money
    {
        $raw = $payload['amount'] ?? $payload['Amount'] ?? null;
        if (! is_scalar($raw) || trim((string) $raw) === '') {
            return null;
        }

        $code = $this->acquiringCurrency($gatewayId);

        try {
            return new DecimalMoneyParser(new ISOCurrencies)->parse((string) $raw, new Currency($code));
        } catch (ParserException) {
            return null;
        }
    }

    /**
     * The currency the merchant account acquires in, read off the credential
     * row. Both spellings are accepted for the same reason
     * {@see \Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure::setting()}
     * accepts both: stored rows hold either.
     *
     * @throws \InvalidArgumentException when the configured currency is one
     *   ConnexPay does not acquire in — a misconfiguration, and not something
     *   a webhook may paper over.
     *
     * @return non-empty-string
     */
    private function acquiringCurrency(GatewayId $gatewayId): string
    {
        $credentials = $this->credentials->findOrFail($gatewayId)->getCredentials();

        return (new ConnexPaySettings(
            accountCurrency: $credentials['accountCurrency'] ?? $credentials['account_currency'] ?? '',
        ))->acquiringCurrency();
    }
}
