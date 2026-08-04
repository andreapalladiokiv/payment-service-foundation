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
 * payment.do carrying `storedCredentialsMode: '0'` — the schemes'
 * account-verification message, and the initial CIT of the chain — while the UPO
 * it returns is a vault id. A subsequent merchant-initiated payment has to send
 * `relatedTransactionId` pointing at that Auth, so a registration that discards
 * it leaves every later renewal unable to anchor itself.
 *
 * Null is the honest answer wherever the registration was not itself a
 * transaction: Nuvei's other route converts a ccTempToken through a pure vault
 * operation, which reaches no issuer and begins no chain.
 */
interface StoredCredentialReferenceProvider
{
    public function getStoredCredentialReference(): ?string;
}
