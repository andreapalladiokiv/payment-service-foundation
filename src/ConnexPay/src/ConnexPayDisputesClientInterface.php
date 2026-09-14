<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay;

use GuzzleHttp\Exception\GuzzleException;

/**
 * The ConnexPay CMS API's transport: one authenticated `GET`, and nothing else.
 *
 * ## Why this is a third interface rather than `get()` on `ConnexPayHttpClientInterface`
 *
 * The other two ConnexPay clients — {@see ConnexPayClient} against `salesapi` and
 * {@see ConnexPayPurchasesClient} against `purchasesapi` — declare `post()` and `put()` because
 * every sales-API and card-issuing operation is a write. The CMS API is read-only: it has two
 * endpoints, both `GET`, and no operation on either of the other two hosts would ever call a
 * `get()` that lived on the shared interface. Widening the shared interface would therefore buy
 * nothing and cost something real — two implementations would carry a method neither of them can
 * honour, and every test double of it would have to answer for a verb the code under test never
 * uses.
 *
 * ## What the interface does not know
 *
 * It does not know the two endpoint paths, the date parameters or the shape of a case. A caller
 * passes the path and the query it wants; the *semantics* — which window means "new and updated"
 * and which means "resolved" — belong to {@see Dispute\DisputePoller}, which is the only thing
 * that reads them. That keeps one place where the CMS query strings are spelled.
 *
 * ## The return type, and why an unreadable body is not an empty list
 *
 * The CMS answers with a JSON array of case objects, so that is what this returns: a list, each
 * element an array of the payload's own fields, untouched. Nothing is decoded into a value object
 * here and nothing is filtered: a field this package does not use today is still in the array for
 * whoever needs it tomorrow.
 *
 * An implementation that cannot read the body **throws rather than answering `[]`**. An empty list
 * is a statement — "the provider has no cases in this window" — and a poller that believes it
 * advances its cursor past a window it never actually read, which loses every case in it. A
 * failure has to stay distinguishable from an absence, and at this boundary the only way to keep
 * them apart is to refuse.
 */
interface ConnexPayDisputesClientInterface
{
    /**
     * @param array<string, string> $query the CMS query string, already reduced to its own spelling
     *
     * @return list<array<string, mixed>>
     *
     * @throws GuzzleException when the call does not answer, or answers with a status that is not a success
     */
    public function get(string $path, array $query): array;
}
