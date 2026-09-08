# Gateway abstraction layer

`techork/payment-service-gateway` — the gateway-agnostic layer between the
domain (ports in Common/Domain) and the provider packages (ConnexPay, Nuvei,
Paynet, Revolut, Stripe). A provider package implements `Contract\Gateway`
directly: no base class, no parameter bags, no request objects handed back for
someone else to send. Each verb takes a typed command and performs the call.

## Outbound: `Contract\Gateway`

`Gateway` has two methods of its own — `getName()` and
`configure(GatewayInfrastructure)` — and inherits its verbs from two
composites, so an implementor writes one `implements Gateway` and a client
depends on the narrow role it actually uses:

- `Role\AcquiringGateway` — `tokenize`, `registerPaymentMethod`, `authorize`,
  `authorizeRebilling`, `charge`, `capture`, `cancel`, `refund`, `retryRefund`,
  assembled from `VaultsInstruments`, `PlacesPayments`,
  `PlacesRebillingPayments`, `CapturesPayments`, `CancelsPayments`,
  `RefundsPayments`.
- `Role\CardIssuer` — `issueVirtualCard`, `updateVirtualCard`,
  `terminateVirtualCard` (`IssuesVirtualCards`).

| Operation | Command | Result |
| --- | --- | --- |
| `tokenize` / `registerPaymentMethod` | `VaultCommand` | `RegistrationResult` |
| `authorize` / `charge` | `PlacementCommand` | `AuthorizationResult` |
| `authorizeRebilling` | `RebillingCommand` | `AuthorizationResult` |
| `capture` | `CaptureCommand` | `GatewayResult` |
| `cancel` | `CancelCommand` | `GatewayResult` |
| `refund` / `retryRefund` | `RefundCommand` | `GatewayResult` |
| `issueVirtualCard` | `IssueCardCommand` | `VirtualCardResult` |
| `updateVirtualCard` | `UpdateCardCommand` | `VirtualCardResult` |
| `terminateVirtualCard` | `TerminateCardCommand` | `GatewayResult` |

A driver builds one operation class per verb — `Stripe\Authorize`,
`Nuvei\Refund`, `Revolut\IssueVirtualCard` — holding its collaborators, with a
pure `payload()` that can be asserted without a network and an action method
that sends and maps. The provider knows what it got back, so it says so
directly; there is no shared response object and no shared folder reading
capability interfaces off one.

### The stack a call travels

`Routing\RoutedGateway` / `RoutedCardIssuer` are the proxies the application
holds: bound to a `GatewayId`, they resolve the tenant's credential once, ask
`GatewayFactory` for the configured driver, and forward. Two decorators wrap
what the resolve returns:

- `Decorator\FailureBoundary` / `CardIssuerFailureBoundary` — folds a thrown
  provider error into a failed result. Composed **inside** the resolve, so a
  missing credential (`findOrFail`) propagates instead of being reported as a
  decline.
- `Decorator\LoggingGateway` / `LoggingCardIssuer` — logs the command and the
  result through `Logger\GatewayLoggerInterface` (default
  `NullGatewayLogger`).

Behaviors worth knowing:

- **Idempotency** — every mutating command carries an optional
  `clientUniqueId`; implementations forward it as the gateway-native mechanism
  (Stripe `Idempotency-Key` header, Nuvei `clientUniqueId`, ConnexPay
  `OrderNumber`). Convention: pass the aggregate id, or `"{id}:suffix"` when
  one aggregate triggers several gateway ops. `UpdateCardCommand` /
  `TerminateCardCommand` deliberately omit it and rely on natural HTTP
  idempotency.
- **Partial capture fallback** — `CaptureCommand` also carries
  `authorizedAmount` + `instrument`; only gateways without native partial
  capture consume them (ConnexPay voids the auth and runs a fresh sale),
  others ignore them.
- **Refund retry** — if a refund fails and the command carries a
  `retryInstrument`, the Laravel `RefundAdapter` calls `retryRefund`
  (ConnexPay Return with ReturnRetryCard, Nuvei Payout). A gateway without the
  primitive surfaces a failed `GatewayResult` rather than an exception.
- `capture` / `cancel` / `refund` take the acquirer's `transactionReference`
  straight from the command and never look one up — that resolution, and its
  missing-row failure, live in the Laravel adapters.
- **Invariant violations are not payment outcomes** — the boundary folds any
  thrown exception into a failed result, which downstream becomes
  `GatewayDeclinedException` and a recorded `PaymentIntentFailed`, i.e. it
  enters the event stream as an acquirer decline. Anything implementing
  `Exception\UnsupportedByGateway` is exempt: it is rethrown. Use it when the
  gateway structurally cannot do what was asked, so a wiring mistake never
  masquerades as a decline.
  - `Exception\UnsupportedInstrument` — instrument the gateway has no product
    for on that operation (a `HostedPayment` to an acquirer with no hosted
    page, raw card data to a hosted-only gateway). Thrown from the `visit*()`
    branch that would otherwise have to invent a payload.
  - `Exception\UnsupportedOperation` — operation the gateway does not have at
    all, whatever the instrument.
  - Not everything unsupported is an invariant: the per-package
    `UnsupportedPaynetOperation` stays unmarked on purpose, and only on the
    cancel path — an unsupported operation has to degrade into a failed
    `GatewayResult` mid-saga rather than throwing, the same way the
    refund-retry path above *depends* on it. Revolut's
    `UnsupportedOperationException` does carry the marker, on every operation it
    throws for: Revolut acquires nothing and has no `retryRefund` to degrade.

### Results

`GatewayResult` (`success` / `reference` / `message` + `metadata`,
`convertedAmount`) is the base; `AuthorizationResult` adds a `Challenge`
(3DS step-up / hosted redirect → `isRequiresAction()`) and AVS/CVC
`CheckResult` fields; `RegistrationResult` adds `customerReference` plus the
same checks. `null` checks mean "no signal", distinct from
`CheckResult::Unchecked`. `GatewayResult::UNNAMED_SUCCESS` is the message every
driver uses for a provider that reports success and names no reference.

`metadata` carries gateway attributes persisted with the reference — notably
`opening_transaction_reference`, written only by the operations that OPEN a
payment intent, because `reference` is overwritten on transition and can no
longer answer which transaction opened it. `RebillingCreateAdapter` reads it
back to anchor a series onto its genesis authorization.

### Contracts the host implements

The Laravel bridge supplies the persistence-side implementations:

| Contract | Role |
| --- | --- |
| `GatewayCredential` / `GatewayCredentialRepository` | tenant credential: `GatewayId` → gateway name + credential array; `all()` feeds webhook routing |
| `GatewayInstrumentRepository` | instrument ↔ gateway reference (token) storage |
| `GatewayTransactionRepository` | payment-intent / refund → gateway transaction reference (+ metadata) |
| `CustomerRepository` | gateway-side customer references, linked to instruments |
| `VirtualCardReferenceRepository` | virtual card id ↔ gateway card reference (both directions) |

`GatewayFactory` keeps its own registry (name → class implementing
`Contract\Gateway`), builds an instance, and calls `configure()` once with a
`ValueObject\GatewayInfrastructure` — credential, decrypter, instrument and
customer repositories, and the settings array. Instances are cached per
`GatewayId`. The Laravel bridge subclasses it (`LaravelGatewayFactory`) to
layer `services.{gateway_name}` app config over the credentials before
`configure()` runs.

## Inbound: webhooks

`Webhook\WebhookRouter` is the framework-agnostic counterpart for inbound traffic:

- `identifyGateway(ServerRequestInterface)` — runs every credential from
  `GatewayCredentialRepository::all()` through its kind's `SignatureVerifier`;
  the first credential whose signature validates is the tenant. Returns a
  `GatewayMatch` (`gatewayId`, `kind`, `externalId` — the delivery's
  idempotency key extracted by the kind's `EventParser`, e.g. Stripe
  `event.id`) or `null`.
- `dispatch(StoredWebhookCall)` — re-parses the stored payload into a
  `ParsedEvent` and invokes the `WebhookEventHandler` registered for
  `(kind, event type)`. Handlers must be idempotent and return a
  `HandlerOutcome`: `Processed`, `Skipped` (also returned when no
  parser/handler is registered) or `Delay` (retry later).

Gateway packages contribute via `Webhook\Contract\WebhookSubscriber`: each
declares its subscriber in `composer.json` under
`extra.laravel.webhook`; the Laravel bridge discovers it and calls
`subscribe()`, which pushes the gateway's verifier/parser into
`VerifierRegistry` and its event-type → handler map into `HandlerRegistry`
(kinds are matched case-insensitively).

Handlers apply state through the `Webhook\Recorder\*` interfaces —
`GatewayAuthorizationRecorder`, `GatewaySuccessRecorder`,
`GatewayFailureRecorder`, `GatewayCancellationRecorder`, `GatewayFeeRecorder`
(out-of-band processor fees for intents / refunds / virtual cards),
`GatewayPaymentMethodRecorder` (default `NoOpGatewayPaymentMethodRecorder`
skips everything; apps with local PaymentMethod storage rebind it),
`RefundProcessingRecorder`, `RefundFailureRecorder` — each returning a
`RecorderOutcome` (`Applied` / `Skipped` / `NotFound` = aggregate not visible
yet, retry). `TransactionIdResolver` reverse-maps gateway references to
internal aggregate ids; `InstrumentReferenceEraser` forgets a detached
payment-method reference without deleting the local instrument.

## Value objects

- `GatewayId` — UUID identifying one credential/tenant configuration.
- `CardSpendCategory` — domain spend taxonomy for virtual card issuance
  (`travel_air`, `travel_lodging`, …, `service_fee`, `business_services`).
  It is a spend **restriction**: issuers decline authorisations from
  merchants outside the category, so pick the narrowest one that fits. Each
  issuing gateway keeps its own mapper; set-valued controls (Revolut) may
  expand one category into several native buckets.
- `PurchaseType` — ConnexPay-native numeric industry enum, mirroring their
  [published table](https://docs.connexpay.com/docs/purchase-types).
  `PurchaseTypeBridge` converts both ways (lossy: no ConnexPay code for
  `TravelRail` / `ServiceFee`, which widen to `Travel` / `MiscAndBusiness`;
  both insurance codes collapse to `Insurance`).

## Testing

Pure unit tests (Pest + Mockery), no credentials or network required:
`vendor/bin/pest` from the package directory.
