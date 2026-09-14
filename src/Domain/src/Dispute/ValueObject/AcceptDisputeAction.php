<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use DateTimeImmutable;
use Money\Money;
use Override;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeAction;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeActionVisitor;

/**
 * Concede the case — Stripe's `close`, Nuvei's `ACCEPT`. Irreversible where it is offered.
 *
 * ## `$disputedAmount` is the ceiling, not the concession
 *
 * The action carries **what is at stake**: the disputed sum the case records, so that whoever
 * presents the action can show the operator the number they are giving up and offer them the choice.
 * It is not the amount being conceded, and there is deliberately no `allowsPartial` flag beside it —
 * whether a part can be conceded instead of the whole is a fact about the provider's API, and a
 * caller learns it from the call's own outcome rather than from a boolean we would have to keep in
 * step with each provider's documentation.
 *
 * What is actually conceded travels on
 * {@see \Techork\PaymentService\Domain\Dispute\Port\Request\AcceptDisputeRequest}: null concedes the
 * whole case, which is the only acceptance Stripe has, and an amount is the partial one Nuvei takes.
 * The action states the ceiling so the caller can choose; the call states the choice.
 *
 * The amount is mandatory rather than optional because an irreversible act needs a price on it
 * wherever it is shown. A case whose sum we do not know cannot be offered for concession at all —
 * which is exactly why a case with no aggregate behind it is offered a response and no concession
 * (there is no recorded sum to put on the action), and why conceding an unpriced case is not
 * expressible here in any shape.
 */
final readonly class AcceptDisputeAction implements DisputeAction
{
    public function __construct(
        public Money $disputedAmount,
        public DateTimeImmutable $respondBy,
    ) {}

    #[Override]
    public function accept(DisputeActionVisitor $visitor): mixed
    {
        return $visitor->visitAccept($this);
    }
}
