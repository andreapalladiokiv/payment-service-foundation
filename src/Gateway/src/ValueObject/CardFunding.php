<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

use Techork\PaymentService\Common\Contract\Challenge;

/**
 * Where a virtual card's money comes from — a closed pair, not a field.
 *
 * Exactly two implementations exist and no third is intended: {@see SaleFunded} names the payment
 * the card draws on, {@see BalanceFunded} names none because none exists. Nothing else belongs in
 * here. The test for admission is whether EVERY gateway with that funding model needs the value:
 * a sale-funded card at any acquirer has a sale to point at, and a balance-funded card at any
 * issuer has nothing to point at. Everything past that — which of a merchant's accounts to draw
 * on, which second identifier an issuer keys card issuance on — is one vendor's concept and rides
 * on that vendor's own channel rather than on this one.
 *
 * Two types rather than one object with a `model` field and five nullable properties, because the
 * nullable version cannot hold its own invariant. A flat bag lets a driver read
 * `$funding->transactionReference` on a balance card and get null back, with only a naming
 * convention standing between it and a runtime guard that has to throw. Here a balance card has no
 * such property to read, so the mistake stops compiling rather than stops at a `LogicException`.
 *
 * Dispatch is `instanceof` at the two places that care, following {@see Challenge}
 * in shape but not in machinery: a visitor earns its keep at three cases and several consumers,
 * and this has two of each.
 */
interface CardFunding
{
    public function model(): CardFundingModel;
}
