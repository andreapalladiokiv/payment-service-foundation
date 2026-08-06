<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\ConnectionContext;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * The payment an authentication is being started for.
 *
 * The instrument in full, unlike anything the firewall sees. That is the difference between
 * deciding and doing: rules match on a BIN and a last four because a rule has no business
 * holding a card number, while an authentication request carries the pan, the expiry and the
 * holder to the directory server. It is why raising a step-up could not stay behind the
 * firewall's fact bag and became a port of this aggregate, which holds the instrument already.
 *
 * What is deliberately absent is anything about the browser — the fingerprint an ACS wants
 * (language, colour depth, screen size, timezone) and the requestor origin. Those are neither
 * the payment's data nor the domain's business, they belong to the request the cardholder is
 * making, and an implementation that needs them takes them through its own constructor from the
 * flow that is calling it. Putting them here would make every aggregate in this package carry
 * one protocol's transport.
 *
 * `$reason` is the firewall's breadcrumb for why a step-up was demanded — an implementation may
 * log it or map it onto a challenge indicator, and must not parse it.
 */
final readonly class InitiateChallengeRequest
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public Money $amount,
        public PaymentInstrument $instrument,
        public BillingAddress $billingAddress,
        public ?ConnectionContext $connection = null,
        public PaymentInitiation $initiation = PaymentInitiation::CardholderInitiated,
        public ?string $reason = null,
    ) {}
}
