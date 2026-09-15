<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use Techork\PaymentService\Gateway\ValueObject\SaleFundingHint;

/**
 * ConnexPay's second name for a sale — the one its card-issuing endpoint keys on.
 *
 * "ITC for short", in the vendor's words: the value comes back in the response to the original
 * sale, and `POST /api/v1/IssueCard` uses it to tie the virtual card to the money behind it. The
 * sale's guid, which every other ConnexPay call is keyed by, is not accepted there — so without
 * this the driver falls back to a Search/Sales lookup whose guid filters ConnexPay silently
 * ignores, and the card is matched by order number or not at all.
 *
 * It lives here and not in the shared card vocabulary because it is one acquirer's identifier,
 * not a fact about sale funding. The common layer holds a slot for it ({@see SaleFundingHint}) and
 * never looks inside; this class is the only thing that can open it, and
 * {@see ConnexPayGateway::issueVirtualCard()} is the only place that does.
 *
 * Absent on the lodged path, structurally: a lodged card draws on the merchant's balance, and
 * `POST /api/v1/IssueCard/LodgedCard` has no such field in its schema at all.
 */
final readonly class IncomingTransactionCode implements SaleFundingHint
{
    public function __construct(public string $value) {}

    /**
     * Null rather than an empty hint when there is nothing to carry, so a stored-but-blank code —
     * which means ConnexPay never sent one, not that we already looked — falls through to the
     * Search/Sales fallback instead of being sent as an empty string.
     */
    public static function fromNullable(?string $value): ?self
    {
        return $value === null || $value === '' ? null : new self($value);
    }
}
