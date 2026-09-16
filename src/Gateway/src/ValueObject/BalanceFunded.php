<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

use Override;

/**
 * The card draws on a pot the merchant funded ahead of time. No payment is named.
 *
 * No fields, and that is the entire content of the case rather than an omission. What a
 * balance-funded card additionally needs is decided one vendor at a time and travels one vendor at
 * a time: which of the business's accounts backs it is a Revolut setting (`CardSettings::$accountIds`),
 * the MID and country lists are ConnexPay lodged fields, and a validity date range is ConfermaPay's.
 * Putting any of them here would make every balance issuer carry one issuer's idea of what a
 * balance is. Named in prose and not with `{@see}` on purpose: a link from here would be an import
 * from the shared layer into one gateway's package, which is the coupling this file exists to deny.
 *
 * The one control that did survive into the common layer is the limit window, and it is on the
 * command beside the spend category rather than in here — it is a spend control the caller asks
 * for, like the category and the amount, not a fact about where the money sits.
 */
final readonly class BalanceFunded implements CardFunding
{
    #[Override]
    public function model(): CardFundingModel
    {
        return CardFundingModel::Balance;
    }
}
