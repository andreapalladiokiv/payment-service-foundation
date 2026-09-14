<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use Override;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeAction;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeActionVisitor;

/**
 * The case is handled in the provider's own panel, and here is where that panel is.
 *
 * ## Weaker than "respond through the operator", and truer for it
 *
 * This replaces the shape that said `Respond` performed by an operator: a response, carried out by
 * a human, at this link. That said more than we know. ConnexPay's CMS API files nothing, so a
 * ConnexPay case is not answered by us — but *which* act the portal offers there (file a response,
 * concede, both) is the portal's business, and an action set asserting `Respond` on the strength of
 * a link would be asserting a capability nobody confirmed. What we can honestly say is where the
 * case lives. Whoever opens the panel finds out the rest, and the action no longer promises
 * something it cannot check.
 *
 * ## The link is nullable, and that is a fact rather than a degradation
 *
 * The only producer of an operator URL in this tree is
 * {@see \Techork\PaymentService\Laravel\Dispute\ConnexPayDisputePortalLink}, and its configuration
 * key (`services.connexpay.dispute_portal_url`) is not set anywhere yet; Nuvei 1.0 has no
 * portal-link producer at all. A null link therefore says "the panel exists and we hold no address
 * for it", which is still a truthful answer about the case, and refusing to build the action without
 * one would report a case as having nothing open on it.
 *
 * That retires an invariant rather than overlooking one: the old constructor **required** a URL
 * whenever the execution was an operator's — "an action with no link is a task nobody can carry out"
 * — and the new rule is deliberately weaker, because the operator knows where the provider's panel
 * is and the case must be surfaced either way.
 *
 * ## It carries nothing else, and one consequence of that is recorded here
 *
 * No {@see EvidenceRequirements} and no deadline. An attributable case can still be read for both —
 * the deadline is on the aggregate, the requirements are built for the response — but a case with no
 * aggregate behind it (a delivery we never received, a case named only by the provider's reference)
 * now answers a link alone while its window runs. That is a real loss on the one path F9 exists for,
 * taken knowingly because the alternative is a link-carrying action that also claims a deadline it
 * has no source for.
 */
final readonly class DashboardDisputeAction implements DisputeAction
{
    public function __construct(public ?string $dashboardUrl) {}

    #[Override]
    public function accept(DisputeActionVisitor $visitor): mixed
    {
        return $visitor->visitDashboard($this);
    }
}
