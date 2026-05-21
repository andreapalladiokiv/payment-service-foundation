<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

use Techork\PaymentService\Common\ValueObject\CreditCard\CheckResult;

/**
 * Capability interface for omnipay {@see \Omnipay\Common\Message\ResponseInterface}
 * implementations that expose card-verification (AVS / CVC) outcomes.
 *
 * Strict parallel to {@see ChallengeProvider} / {@see CustomerReferenceProvider}:
 * {@see PaymentGatewayRouter} pulls signals via `instanceof` checks and folds
 * them into {@see GatewayResult}. Each gateway implementation translates its
 * raw response format (Stripe's pre-normalized strings, Nuvei/ConnexPay's
 * scheme single-letters) into {@see CheckResult} on its own — no shared
 * letter-mapping lives at this layer.
 *
 * `null` returns mean "the response does not carry a signal for that field"
 * — distinct from {@see CheckResult::Unchecked}, which is a real signal
 * meaning "verification was not requested".
 */
interface CardChecksProvider
{
    public function getAddressLineCheck(): ?CheckResult;

    public function getPostalCodeCheck(): ?CheckResult;

    public function getCvcCheck(): ?CheckResult;
}
