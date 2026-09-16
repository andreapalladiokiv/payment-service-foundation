<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Command;

use InvalidArgumentException;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * Which case to ask a provider about.
 *
 * The provider's reference is the whole of it: it is what the provider's own read is addressed
 * with — Stripe's `dp_…` on `GET /v1/disputes/:id`, Nuvei's id with its `/` and `+`, ConnexPay's
 * `CaseNumber` — and it is the one field that cannot be left out, because a read without it is not
 * a narrower read but a different one (a list of every case the account has ever had).
 *
 * ## Why it is a query and not a `…Command`
 *
 * The other input objects in this directory name an act the gateway will perform; this one names a
 * question, and the object that answers it ({@see \Techork\PaymentService\Gateway\Contract\DisputeCaseReading})
 * is a read rather than a result. Calling it a command would say a provider state was changed by
 * it — and at one provider the read is the only dispute call there is.
 *
 * Nothing here carries our own state of the case. A provider's answer cannot show that we already
 * conceded — the case reads `lost` either way, as though the issuer had decided it — and that part
 * of "is this case still waiting on us" is answered where the aggregate is held, not by the
 * provider that never knew it.
 */
final readonly class DisputeCaseQuery
{
    public function __construct(
        public GatewayId $gatewayId,
        public string $disputeReference,
    ) {
        trim($disputeReference) !== ''
            || throw new InvalidArgumentException(
                'A dispute read must name the case it asks about; without a reference the '
                . 'provider is asked for every case on the account instead of this one.',
            );
    }

}
