<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

/**
 * The issuing counterpart to {@see AcquiringGateway}, and the second of the two composites this
 * layer has.
 *
 * The cut between them is not taxonomy: it is where the providers actually differ. Revolut issues
 * and acquires nothing; Stripe and Nuvei acquire and issue nothing; only ConnexPay does both. One
 * composite spanning the two would have four of five providers declaring most of what they
 * refuse — the refused bequest the roles exist to prevent.
 *
 * Like its counterpart, it exists for the proxy and the decorators, and nothing outside
 * `Routing/` and `Decorator/` may type-hint it.
 */
interface CardIssuer extends IssuesVirtualCards {}
