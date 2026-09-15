<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\ValueObject;

use Techork\PaymentService\Common\Contract\PaymentInstrument;

/**
 * A gateway's own extra name for the sale a card draws on.
 *
 * Some acquirers hold more than one identifier for one sale and key card issuance on a different
 * one than they key everything else on. ConnexPay is the case that forced this: its sale answers
 * with a guid, its `IssueCard` endpoint will not accept that guid, and the code it does accept —
 * the Incoming Transaction Code — is documented as the only thing tying a virtual card to the
 * money behind it. The alternative to carrying it is a Search/Sales round trip whose guid filters
 * ConnexPay silently ignores, i.e. a card matched by order number or not matched at all.
 *
 * So it must reach the driver, and it must not reach the vocabulary. {@see SaleFunded} holds one
 * of these and never looks inside it; the gateway package that needs one declares its own
 * implementation and is the only code that can read it back out. That is the same arrangement
 * {@see PaymentInstrument} already has — a contract in the
 * common layer, implementations wherever they belong — and it is deliberately NOT an untyped
 * `array $gatewayOptions`, which is the shape this repository has already declined once for
 * exactly these ConnexPay fields.
 *
 * Empty on purpose: the common layer has no question to ask a hint, only a slot to carry it.
 */
interface SaleFundingHint {}
