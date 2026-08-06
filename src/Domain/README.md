# Domain (event-sourced payment aggregates)

`techork/payment-service-domain` — the event-sourced core of the payment
service, built on [EventSauce](https://eventsauce.io). Three aggregates
(`PaymentIntentAggregate`, `CheckoutAggregate`, `SubscriptionAggregate`) plus
the driven ports the payment flows call out through. Pure PHP, no framework and
no I/O: gateways plug in behind port interfaces, and the Laravel package
supplies the port adapters (`OmnipayCreatePort`, `OmnipayRebillingCreatePort`,
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
| `create()` | `CreatePort` | positive amount, usable instrument, hosted ⇒ `Immediate` capture | `PaymentIntentCharged` (Immediate) or `PaymentIntentAuthorized` / `PaymentIntentFailed` |
| `confirmChallenge()` | `ConfirmChallengePort` | status `RequiresAction` | `PaymentIntentCharged` or `PaymentIntentAuthorized` / `PaymentIntentFailed` |
| `capture()` | `CapturePort` | status `Authorized`, capture method not `Immediate` | `PaymentIntentCaptured` (no decline event — `GatewayDeclinedException` propagates out of `capture()` instead of being recorded) |
| `cancel()` | `CancelPort` | status `Authorized` or `RequiresAction` | `PaymentIntentCancelled` / `PaymentIntentFailed` |
| `refund()` | `RefundPort` | status `Charged`, positive amount, same currency, ≤ `refundableAmount()` | `RefundProcessed` / `RefundFailed` |
| `recordFee()` | — | none (out-of-band signal, any status) | `PaymentIntentFeeRecorded` |
| `recordRefundFee()` | — | refund id must exist | `RefundFeeRecorded` |

`CaptureMethod::Immediate` charges inline at create; `Automatic` / `Manual`
authorize first and settle via `capture()` (calling `capture()` on an
Immediate intent throws `PaymentIntentCannotBeCaptured::immediate()`).

A `HostedPayment` instrument therefore requires `Immediate`: the buyer pays on
the gateway's own page, so we hold nothing to authorize now and capture later,
and every gateway in the fleet implements hosted on the charge path only.
`create()` rejects the other two capture methods with
`InvalidPaymentIntent::hostedPaymentRequiresImmediateCapture()` rather than
letting the port route to `authorize()` and return what looks like a decline.

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
fixed-amount), `MerchantUnscheduled` (on-demand card-on-file). It lives in
`Common\ValueObject` rather than here, because the gateway package has to name
it to put the indicator on the wire and cannot see this one. It rides on the
creation-flow events (`RequiresAction` / `Authorized` / `Charged` / `Failed`)
and it is what a firewall rule scopes a step-up on. Inspection itself applies to
every payment: an MIT is screened like anything else, and what it cannot do is
answer a challenge, so a `Challenge` verdict on one is refused rather than
attempted. Chains that should not ask match on
`payment_intent.is_cardholder_initiated`.

That is a rule about step-ups, not about authentication: EMV 3DS
requestor-initiated (3RI) obtains a cryptogram with no cardholder present, so an
MIT carrying a `ThreeDSResult` is ordinary. Note also that
`CardholderInitiated` does not mean "first payment of a series" — a one-off
checkout is CIT too — so an acquirer that wants the initiating transaction of a
stored-credential series flagged needs a distinction this enum does not draw.

**Firewall.** `PaymentIntentFirewallPort::evaluate(PaymentIntentFirewallRequest):
FirewallDecision` is defined here and consulted by
`PaymentIntentAggregate::create()` itself, not by the application flow, before
the gateway call is spent. A chain answers with a `FirewallVerdict` of `Allow`,
`Deny` or `Challenge`, and all three are actions a caller can carry out. There is
no "nothing matched" — what the absence of a match means belongs to the chain,
which says so through its own strategy — and no challenge artefact on the
decision, because deciding that a cardholder must be authenticated and
authenticating them are different jobs.

Every payment is inspected. Two used to be skipped: a merchant-initiated one, on
the reasoning that nobody is present to answer a step-up, and one arriving with a
finished 3DS result, on the reasoning that the liability shift is already
claimed. Both reasons were sound and both were about the step-up only; once a
rule could refuse a payment outright, the skips meant deny rules went unasked —
and the second was reachable by anyone, since the result comes from the caller
and the coherence check on it says its fields agree, not that an issuer saw the
cardholder. One skip remains: a non-card instrument, which the firewall request
cannot describe.

**Challenges the firewall asked for.** `ChallengePort` is the aggregate's own
port for the authentication a `Challenge` verdict demands, sitting beside
`ConfirmChallengePort` — this one talks to whoever authenticates cardholders,
that one talks to the gateway and places the payment afterwards. It takes the
full instrument, which is why it could not live behind the firewall's fact bag: a
rule matches on a BIN and a last four, an authentication request needs the pan.

It answers a `ChallengeOutcome` with three cases, because an authentication has
three endings: `raised` parks the payment at `RequiresAction` with the artefact
to present, `passed` sends it to the acquirer carrying the provider's own result
(the frictionless case, and the common one), and `refused` fails it. Which of the
port's two questions gets asked turns on whether a result came in with the
payment: `initiate` when none did, `verify` when one did — weighed rather than
taken, since the alternative is that presenting one satisfies any step-up rule.
A chain demanding a step-up with no port installed raises
`ChallengeCannotBeRaised`, a `LogicException` so an application mapping business
outcomes onto refusals cannot swallow it.

The default firewall is `NullPaymentIntentFirewall`, which allows — nothing is
installed, so nothing is inspected.

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
`HostedPayment` marker; `billingAddress` is required, and an import with no
address on file passes `BillingAddress::unknown()` rather than null, so the rest
of the lifecycle's address requirement still holds.

## Checkout

`CheckoutAggregate` — statuses `pending`, `charged`, `cancelled`.
`pay(PayCheckoutCommand, Port\CheckoutCapturePort)` requires a pending,
unexpired checkout and an **Authorized** `PaymentIntentAggregate` with the exact
checkout amount; it then captures that intent through its own port and records
`CheckoutPaymentSubmitted`. A checkout created with a
`SubscriptionPlan` is a subscription checkout: `pay()` must then also receive
the `SubscriptionAggregate` (plan and subscription both set or both null), the
subscription must have no cancellation recorded — even a still-pending one
blocks payment — and the payment intent must be the one
bound to it (`lastPaymentIntentId()`). `cancel()` only from pending.

There is no failure path and no `Failed` status: a capture either moves the money
or throws without recording anything, leaving the checkout `Pending` and payable
again. A checkout that should not be retried is `Cancelled`.

**Why authorized and not charged.** The checkout is what decides whether the
money may be taken, so it must still be takeable when `pay()` runs. An intent
charged inline at create (`CaptureMethod::Immediate`) has already moved the
money before any checkout check ran, and the same intent could then be handed to
a second checkout with nothing left to refuse. So a checkout payment is created
with `Automatic` / `Manual` capture, and the checkout captures it after its
checks pass. A captured intent lands in `Charged` — there is no separate
`Captured` status.

**One payment intent pays at most one checkout** falls out of that, rather than
needing a rule of its own. Capture consumes the intent: the first checkout leaves
it `Charged`, so a second one fails the `Authorized` check above and is refused
before the acquirer is touched — not a rejected double charge, a double charge
never requested. No read model, no projection, no cross-aggregate lookup: the
shared resource enforces its own consumption.

That holds **sequentially**, which is the double-submit case. It does not
serialize two genuinely concurrent payments: each hydrates the intent and reads
`Authorized` from its own copy, and the gateway capture happens before either
event is appended, so the aggregate guard rejects the second set of bookkeeping
after the money has already left twice. The domain has no way to close that —
it belongs to the adapter, via the gateway's own idempotency key keyed on the
intent (`"{paymentIntentId}:capture"`, the convention documented on
`PaymentGatewayInterface`). Anything claiming the aggregate is a concurrency
backstop is wrong.

The checkout declares **its own** `Port\CheckoutCapturePort` rather than
borrowing the payment intent's `CapturePort`, for the same reason each aggregate
declares its own firewall port: it is typed to what this aggregate holds
(`Port\Request\CheckoutCaptureRequest` — its own id, the intent it was handed,
its own amount).

It returns `void`, and there is no outcome to inspect, because **capture has no
business failure mode**: the money was reserved when the intent was authorized.
So there are two answers only — it returned and the money moved, or it threw and
something infrastructural went wrong. On the throw `pay()` records nothing, which
leaves the checkout Pending and makes the retry the same call again — burning the
checkout over a lost connection would be the wrong trade, which is why the
aggregate has no failure event to record in the first place.

Two obligations sit with the implementation, and the guarantee above depends on
the first:

- **Capture through `PaymentIntentAggregate::capture()`**, not straight at the
  gateway: its `Authorized`-only check is what stops the intent being consumed
  twice, and a port that bypasses it satisfies the type while losing the
  invariant.
- **Commit the intent's `PaymentIntentCaptured` together with the checkout's
  `CheckoutPaymentSubmitted`.** A captured intent with no paid checkout is a
  charged customer holding an unpaid order.

## Subscription

`SubscriptionAggregate` — statuses `trialing`, `active`, `cancelled`
(`SubscriptionStatus`). `create()` records the `SubscriptionPlan` (amount,
`BillingInterval` of N day/week/month/year, optional trial `DateInterval`) and
a `PaymentMethodId`. `activate(ActivateSubscriptionCommand, Port\SubscriptionCapturePort)`
requires Trialing plus an **Authorized** payment intent matching the plan amount,
then captures it through the subscription's own port; `renew()` starts the next
`BillingPeriod`.
Cancellation is deferred: `cancel()` records the reason but `status()` keeps
returning `Active` until `currentPeriodEnd()` passes — except on Trialing with
no billing period, where it terminates immediately. `revertCancellation()`
clears a still-pending cancellation. Renewal is refused while a cancellation is
pending.

Activation follows the checkout shape exactly — authorized intent → the checks
this aggregate owns → capture through its own port — and gets the same property
for the same reason: **one payment intent activates at most one subscription**,
because capture consumes the intent and the second activation then fails the
`Authorized` check. The same limit applies too: it holds sequentially, not for two
concurrent activations (see `Port\SubscriptionCapturePort`).

`Port\SubscriptionCapturePort` returns `void` for the same reason the checkout's
does: capture has no business failure mode, so a failure propagates from the port
untouched and `activate()` records nothing — the subscription stays in the status
it already had (Trialing, or Cancelled if a cancellation had already terminated
it) and a retry is the same call again.

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
