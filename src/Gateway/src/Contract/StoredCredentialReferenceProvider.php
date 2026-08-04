<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Contract;

/**
 * Implemented by a registration response whose gateway call was itself the
 * transaction that ESTABLISHED the stored credential, and which therefore has an
 * identifier the later payments of that series have to quote back.
 *
 * Distinct from the transaction reference a registration already returns. That one
 * names the stored instrument — Nuvei's `userPaymentOptionId`, ConnexPay's card
 * GUID — and answers "what do I charge next time". This one names the
 * authorization that created the arrangement, and answers "which transaction
 * began this series", which is a different question and a different value.
 *
 * They coincide for nobody. Nuvei registers a card with a zero-amount `Auth` on
 * payment.do, an issuer-reaching transaction with a `transactionId` of its own,
 * while the UPO it returns is a vault id. Their reference makes the anchor
 * mandatory on the later payment — `relatedTransactionId`, "REQUIRED … For
 * recurring/rebilling and MIT: the transactionId from the response to the initial
 * transaction" — so a registration that discards its transactionId throws away the
 * only candidate it will ever have.
 *
 * CANDIDATE is the honest word. Nuvei documents that the anchor is the initial
 * CIT's transactionId, and separately that a zero-amount authorization is the
 * approved way to store a card for later use, but nowhere states that the second
 * may serve as the first. Every rebilling example they publish uses a real-value
 * payment. So keeping this value costs nothing and is the only way to find out,
 * but until a sandbox probe or their integration team confirms it, no caller
 * should treat a stored anchor as proof the chain is valid. If it turns out the
 * anchor must be a real payment, the value moves from the instrument to the
 * initiating payment — a different scope, and a different lookup.
 *
 * Null is the honest answer wherever the registration was not itself a
 * transaction: Nuvei's other route converts a ccTempToken through a pure vault
 * operation, which reaches no issuer and begins no chain.
 */
interface StoredCredentialReferenceProvider
{
    public function getStoredCredentialReference(): ?string;
}
