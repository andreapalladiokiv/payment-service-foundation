# Domain (event-sourced payment aggregates)

`techork/payment-service-domain` — the event-sourced core of the payment
service, built on [EventSauce](https://eventsauce.io). Three aggregates
(`PaymentIntentAggregate`, `CheckoutAggregate`, `SubscriptionAggregate`) plus
the driven ports the payment flows call out through. Pure PHP, no framework and
no I/O: gateways plug in behind port interfaces, and the Laravel package
supplies the port adapters (`OmnipayCreatePort`, `FraudScreeningCreatePort`,
...), repositories, snapshots and event-sourcing glue.

Each aggregate namespace has the same shape: `Command/` (interfaces the
consuming application implements — they are contracts, not DTOs), `Event/`
(`SerializablePayload` events with snake_case payloads), `Exception/`
(`DomainException` guards), `ValueObject/`, and a
`*AggregateRepositoryInterface` (`retrieve()` / `persist()`). Amounts are
`Money\Money` throughout.

## PaymentIntent

`PaymentIntentAggregate` — statuses `requires_action`, `authorized`, `charged`,
`failed`, `cancelled` (`PaymentIntentStatus`). Gateway calls happen *inside*
the aggregate methods through ports; a port signals refusal by throwing
`GatewayDeclinedException`, which the aggregate converts into a failure event
instead of propagating. Violated domain invariants throw before the port is
ever called.

| Method | Port | Guards | Success event / decline event |
| --- | --- | --- | --- |
| `create()` | `CreatePort` | positive amount, usable instrument | `PaymentIntentCharged` (Immediate) or `PaymentIntentAuthorized` / `PaymentIntentFailed` |
| `confirmChallenge()` | — | status `RequiresAction` | `PaymentIntentCharged` or `PaymentIntentAuthorized` / `PaymentIntentFailed` |
| `capture()` | `CapturePort` | status `Authorized`, capture method not `Immediate` | `PaymentIntentCaptured` / `PaymentIntentFailed` |
| `cancel()` | `CancelPort` | status `Authorized` or `RequiresAction` | `PaymentIntentCancelled` / `PaymentIntentFailed` |
| `refund()` | `RefundPort` | status `Charged`, positive amount, same currency, ≤ `refundableAmount()` | `RefundProcessed` / `RefundFailed` |
| `recordFee()` | — | none (out-of-band signal, any status) | `PaymentIntentFeeRecorded` |
| `recordRefundFee()` | — | refund id must exist | `RefundFeeRecorded` |

`CaptureMethod::Immediate` charges inline at create; `Automatic` / `Manual`
authorize first and settle via `capture()` (calling `capture()` on an
Immediate intent throws `PaymentIntentCannotBeCaptured::immediate()`).

**Challenges (3DS / hosted redirect).** When `CreateOutcome::$challenge` is
non-null the aggregate parks at `RequiresAction` and waits for
`confirmChallenge(ChallengeResult)`, which rejoins the original charge/authorize
path. `ChallengeFailureReasonExtractor` decides success: 3DS `Successful` and
`NotAvailable` both qualify (both grant the liability shift); a
`RedirectResult` always counts as success because failed hosted flows arrive
via webhook, never as a result. `CreatePaymentIntentCommand::challengeResult()`
can also carry a pre-authenticated result from an external MPI.

**Payment initiation (CIT/MIT).** `PaymentInitiation` models the Stored
Credential Framework: `CardholderInitiated`, `MerchantRecurring` (scheduled
fixed-amount), `MerchantUnscheduled` (on-demand card-on-file). It rides on the
creation-flow events (`RequiresAction` / `Authorized` / `Charged` / `Failed`)
and gates the cardholder-facing controls — fraud
screening and 3DS step-up apply only to CIT; an MIT payment must never be
forced into a challenge.

**Risk.** `RiskDecisionPort::decide(RiskAssessmentRequest): RiskOutcome` is
defined here but consulted by the application flow, not the aggregate. The card
is screened at two `RiskPhase`s: `Registration` (zero amount, when the payment
method is stored) and `Authorization` (real amount, with the target
`gatewayId`). The resulting `RiskAction` is `Require3ds`, `Skip3ds` or `Allow`
— risk never blocks a payment on its own, it routes to stronger
authentication. Implementations must not throw for business outcomes and own
the fail-open/fail-closed policy. `fraudReference` links the two screenings.

**Refunds.** `Refund` is a child aggregate on the same event stream (the
parent uses EventSauce's `AggregateRootWithAggregates`), keyed by `RefundId`;
it only projects state. Partial
refunds are allowed up to `refundableAmount()` = captured amount minus all
*processed* refunds (failed refunds don't consume it). An optional
`retryInstrument` redirects the refund to an alternative instrument when the
original card can't accept it.

**FX.** `CreateOutcome` / `CaptureOutcome` carry the gateway's FX-settled
`convertedAmount`, recorded on `PaymentIntentCharged` / `PaymentIntentCaptured`.
Authorize-only flows have no converted amount — it surfaces at capture.

**Imports.** `PaymentIntentImported` and `RefundImported` replay existing
records from gateway exports or settlement files. The instrument is the open
`PaymentInstrument` contract so hosted-page imports can carry a
`HostedPayment` marker; `billingAddress` is nullable for the same reason.

## Checkout

`CheckoutAggregate` — statuses `pending`, `charged`, `failed`, `cancelled`.
`pay(PayCheckoutCommand)` requires a pending, unexpired checkout and a
**Charged** `PaymentIntentAggregate` with the exact checkout amount, then
records `CheckoutPaymentSubmitted`. A checkout created with a
`SubscriptionPlan` is a subscription checkout: `pay()` must then also receive
the `SubscriptionAggregate` (plan and subscription both set or both null), the
subscription must have no cancellation recorded — even a still-pending one
blocks payment — and the payment intent must be the one
bound to it (`lastPaymentIntentId()`). `cancel()` only from pending;
`recordChargeFailure()` records `CheckoutFailed` with a reason.

## Subscription

`SubscriptionAggregate` — statuses `trialing`, `active`, `cancelled`
(`SubscriptionStatus`). `create()` records the `SubscriptionPlan` (amount,
`BillingInterval` of N day/week/month/year, optional trial `DateInterval`) and
a `PaymentMethodId`. `activate()` requires Trialing plus a Charged payment
intent matching the plan amount; `renew()` starts the next `BillingPeriod`.
Cancellation is deferred: `cancel()` records the reason but `status()` keeps
returning `Active` until `currentPeriodEnd()` passes — except on Trialing with
no billing period, where it terminates immediately. `revertCancellation()`
clears a still-pending cancellation. Renewal is refused while a cancellation is
pending.

## Persistence

All three aggregates implement `AggregateRootWithSnapshotting` with explicit
`createSnapshotState()` / `reconstituteFromSnapshotState()`. The polymorphic
`Challenge` / `ChallengeResult` contracts are serialized by
`ChallengeArraySerializer` / `ChallengeResultArraySerializer` using a `type`
discriminator (`three_ds` / `redirect`). Event payloads deserialize
defensively (`initiation`, `metadata` default when absent) so pre-existing
streams replay cleanly.

## Testing

Pest with `eventsauce/pest-utilities`; `tests/Support/` provides
`AggregateRootTestCase` subclasses per aggregate. Because
`PaymentIntentAggregate::create()` takes a `CreatePort`, tests construct the
aggregate directly with stub ports instead of the `when($command)` helper —
see `tests/Unit/Domain/PaymentIntent/PaymentIntentAggregateTest.php` for
anonymous-class command and port stubs.
