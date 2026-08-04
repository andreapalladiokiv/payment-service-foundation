<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent\Port\Request;

use Money\Money;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Domain\PaymentIntent\ValueObject\PaymentIntentId;

/**
 * `amount` is what to capture; `authorizedAmount` is what was held. Both, because a
 * gateway cannot always tell one from the other on its own and the difference decides
 * how the capture is performed.
 *
 * ConnexPay is why. It "can only capture the original amount that was authorized",
 * and its documented procedure for anything less is to void the authorization and run
 * a fresh sale — which needs a card, hence the instrument. Neither fact is reachable
 * from its side: an authorization has no lookup endpoint, does not appear in
 * Search/Sales, and the card on a sale record is masked (`last4`, `cardType`) with no
 * token. Measured, not assumed. Gateways with native partial capture ignore both.
 *
 * Passing them is not optional for correctness: without `authorizedAmount` the
 * ConnexPay adapter cannot detect that a partial capture was asked for, and by its own
 * words "the full hold would be captured silently" — the domain asking for 30 of 100
 * and the cardholder being charged 100.
 */
final readonly class CaptureRequest
{
    public function __construct(
        public PaymentIntentId $paymentIntentId,
        public Money $amount,
        public Money $authorizedAmount,
        public PaymentInstrument $instrument,
    ) {}
}
