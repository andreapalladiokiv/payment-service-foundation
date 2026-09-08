<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Role;

/**
 * Everything an acquiring gateway does, as one type.
 *
 * Not a contract anyone should depend on — a caller depends on the role it uses, which is the
 * point of there being roles at all. This exists for the three classes that genuinely do all of
 * it: the routing proxy and the two decorators. A decorator must be substitutable for what it
 * wraps, so it has to name every role in the stack, and spelling that as an intersection type at
 * each of the fourteen places would say the same thing worse.
 *
 * It grows a role per migrated operation and stops at the acquiring set. Card issuing is a
 * different product with different clients and a nearly disjoint set of providers — Revolut
 * acquires nothing, Stripe and Nuvei issue nothing — so it gets its own composite rather than
 * widening this one.
 *
 * Nothing outside `Routing/` and `Decorator/` may type-hint it.
 */
interface AcquiringGateway extends
    PlacesPayments,
    PlacesRebillingPayments,
    CapturesPayments,
    CancelsPayments,
    RefundsPayments,
    VaultsInstruments {}
