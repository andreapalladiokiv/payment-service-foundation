# Gateway abstraction layer

`techork/payment-service-gateway` — the gateway-agnostic layer between the
domain (ports in Common/Domain) and the provider packages (ConnexPay, Nuvei,
Paynet, Revolut, Stripe). Built on [Omnipay](https://github.com/thephpleague/omnipay-common):
provider gateways are Omnipay gateways extended with this package's `Gateway`
contract, and `PaymentGatewayRouter` translates typed domain calls into
Omnipay request/response round-trips.

## Outbound: `PaymentGatewayInterface`

`PaymentGatewayRouter` implements `Contract\PaymentGatewayInterface`. Every
call resolves the tenant's `GatewayCredential` by `GatewayId`, obtains a
cached gateway instance from `GatewayFactory`, and collapses the Omnipay
response (or any thrown exception) into an immutable result object — the
router never throws for gateway failures.

| Operation | Omnipay call | Result |
| --- | --- | --- |
| `tokenize` | `createCard` | `RegistrationResult` |
| `createPaymentMethod` | `createPaymentMethod` | `RegistrationResult` |
| `authorize` | `authorize` | `AuthorizationResult` |
| `charge` | `purchase` | `AuthorizationResult` |
| `capture` | `capture` | `GatewayResult` |
| `cancel` | `void` | `GatewayResult` |
| `refund` | `refund`, then `retryRefund` (see below) | `GatewayResult` |
| `issueVirtualCard` / `updateVirtualCard` | same names | `VirtualCardResult` |
| `terminateVirtualCard` | `terminateVirtualCard` | `GatewayResult` |

Behaviors worth knowing:

- **Idempotency** — every mutating op takes an optional `$clientUniqueId`;
  implementations forward it as the gateway-native mechanism (Stripe
  `Idempotency-Key` header, Nuvei `clientUniqueId`, ConnexPay `OrderNumber`).
  Convention: pass the aggregate id, or `"{id}:suffix"` when one aggregate
  triggers several gateway ops. `updateVirtualCard` / `terminateVirtualCard`
  deliberately omit it and rely on natural HTTP idempotency.
- **Partial capture fallback** — `capture` also forwards `authorizedAmount` +
  `instrument`; only gateways without native partial capture consume them
  (ConnexPay voids the auth and runs a fresh sale), others ignore them.
- **Refund retry** — if the standard refund fails and a `$retryInstrument`
  was given, the router calls `retryRefund` (ConnexPay Return with
  ReturnRetryCard, Nuvei Payout). Gateways without the method surface a
  failed `GatewayResult` through the catch — never an exception.
- `issueVirtualCard` throws `RuntimeException` when no transaction reference is
  stored for the payment intent; `capture` / `cancel` / `refund` take the
  acquirer's `$transactionReference` straight from the caller and never look one
  up — that resolution, and its missing-row failure, live in the Laravel ports.
- **Invariant violations are not payment outcomes** — the router folds any
  thrown exception into a failed result, which downstream becomes
  `GatewayDeclinedException` and a recorded `PaymentIntentFailed`, i.e. it
  enters the event stream as an acquirer decline. Anything implementing
  `Exception\UnsupportedByGateway` is exempt: it is rethrown from all five
  builders. Use it when the gateway structurally cannot do what was asked, so
  a wiring mistake never masquerades as a decline.
  - `Exception\UnsupportedInstrument` — instrument the gateway has no product
    for on that operation (a `HostedPayment` to an acquirer with no hosted
    page, raw card data to a hosted-only gateway). Thrown from the `visit*()`
    branch that would otherwise have to invent a payload.
  - `Exception\UnsupportedOperation` — operation the gateway does not have at
    all, whatever the instrument.
  - Not everything unsupported is an invariant: the per-package
    `UnsupportedPaynetOperation` stays unmarked on purpose, and only on `void()`
    because that backs `cancel()` — an unsupported operation has to degrade into
    a failed `GatewayResult` mid-saga rather than throwing, the same way the
    refund-retry path above *depends* on it. Revolut's
    `UnsupportedOperationException` does carry the marker, on every operation it
    throws for: Revolut acquires nothing and has no `retryRefund` to degrade.

### Results and response capabilities

`GatewayResult` (`success` / `reference` / `message` + `metadata`,
`convertedAmount`) is the base; `AuthorizationResult` adds a `Challenge`
(3DS step-up / hosted redirect → `isRequiresAction()`) and AVS/CVC
`CheckResult` fields; `RegistrationResult` adds `customerReference` plus the
same checks. `null` checks mean "no signal", distinct from
`CheckResult::Unchecked`.

Extra signals are pulled off Omnipay responses via optional capability
interfaces (`instanceof` checks in the router): `ChallengeProvider`,
`CardChecksProvider`, `CustomerReferenceProvider`, `TransactionMetadataProvider`
(gateway attributes persisted with the reference, e.g. ConnexPay's incoming
transaction code), `ConvertedAmountProvider` (FX-settled amount) and
`VirtualCardResponseInterface`. Provider request classes share the
`Concern\InstrumentParameters` trait for the common parameters the router
passes (`instrument`, `gateway`, `decrypter`, `referenceResolver`, `threeDS`, …).

### Contracts the host implements

The Laravel bridge supplies the persistence-side implementations:

| Contract | Role |
| --- | --- |
| `GatewayCredential` / `GatewayCredentialRepository` | tenant credential: `GatewayId` → gateway name + credential array; `all()` feeds webhook routing |
| `GatewayInstrumentRepository` | instrument ↔ gateway reference (token) storage |
| `GatewayTransactionRepository` | payment-intent / refund → gateway transaction reference (+ metadata) |
| `CustomerRepository` | gateway-side customer references, linked to instruments |
| `VirtualCardReferenceRepository` | virtual card id ↔ gateway card reference (both directions) |

`GatewayFactory` extends Omnipay's factory with `createForCredential()`:
registry maps gateway name → class (must implement `Contract\Gateway`),
instances are initialized with the credential array and cached per
`GatewayId`. The Laravel bridge subclasses it (`LaravelGatewayFactory`) to
layer `services.{gateway_name}` app config over the credentials and
re-initialize. `Logger\GatewayLoggerInterface` records every request/response
pair; defaults to `NullGatewayLogger`.

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
