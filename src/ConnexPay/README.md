# ConnexPay gateway

`techork/payment-service-connexpay` — full acquiring + virtual-card-issuing
gateway for [ConnexPay](https://docs.connexpay.com/). `ConnexPayGateway`
(Omnipay `AbstractGateway`, name `connexpay`) talks to **two separate
ConnexPay APIs**, each with its own base URL and its own bearer token:

| Client | API | Sandbox / Production |
| --- | --- | --- |
| `ConnexPayClient` | Sales API (sales, auths, captures, returns, void, verify, search) | `sandboxsalesapi.connexpay.com` / `salesapi.connexpay.com` |
| `ConnexPayPurchasesClient` | Purchases API (virtual card issuance) | `sandboxpurchasesapi.connexpay.com` / `purchasesapi.connexpay.com` |

Both authenticate lazily with an OAuth **password grant**
(`POST /api/v1/token`, `grant_type=password`) and cache the access token for
the lifetime of the client instance.

## Credentials

`ConnexPayGateway::initialize()` accepts (snake_case variants like
`device_guid` are translated by Omnipay's `Helper`):

| Credential | Meaning |
| --- | --- |
| `username` / `password` | API login, used for the token grant on **both** APIs and for webhook Basic Auth |
| `deviceGuid` | Sent as `DeviceGuid` on every Sales-API transaction call (`Search/*` lookups don't send it) |
| `merchantGuid` | Required for virtual card issuance and Search/Sales lookups |
| `environment` | `sandbox` (default) or `production` — switches both base URLs |
| `accountCurrency` | Currency the merchant account is provisioned in (ConnexPay's "Accounting Currency"). Empty means `USD`. Only `USD`, `CAD`, `GBP`, `EUR` are accepted — see below |
| `merchantName` | Storefront name shown to the buyer on the hosted payment page. Required by the hosted flow and unused elsewhere, so it may stay empty on a merchant that never takes hosted payments. Not the card-statement descriptor — that arrives per transaction as `statementDescription` |

### Why `accountCurrency` exists

ConnexPay's v1 API carries **no currency field**, on any request or response, and
neither does the Sale webhook. It bills whatever goes into `Amount` in the
account's own currency, decided on ConnexPay's side. So an amount in any other
currency is silently rebranded rather than rejected — a `Money` of ¥5,000 (about
$32) would be charged as **$5,000** on a USD account, with nothing anywhere to
reconstruct what was meant.

`formatMoney()` therefore refuses any amount whose currency is not
`accountCurrency`. Verified two ways: the OpenAPI source behind the reference
(`sales-api.json`, updated 2026-07-16) has no currency property on any of the 28
acquiring paths, and a sandbox probe
(`tests/Integration/ConnexPayCurrencyFieldProbeTest.php`) found every spelling of
a currency field silently dropped — including when sent as a type mismatch, which
a bound property would have rejected by name.

Configuring a currency ConnexPay does not *acquire* in fails immediately rather
than disabling the check: acceptance is limited to those four currencies, while
card **issuing** supports roughly thirty. Matching an amount against an
issuing-only currency would reinstate exactly the mis-billing the guard prevents.

## Operations

| Gateway method | Request class | Endpoint | Notes |
| --- | --- | --- | --- |
| `createCard()` | `CreateCardRequest` | `POST /api/v1/verify` | $0 verification; card GUID becomes the transaction reference |
| `createPaymentMethod()` | `CreatePaymentMethodRequest` | `POST /api/v1/verify` | Re-verifies a stored card GUID together with `Card.Customer` so ConnexPay creates/links the customer and returns fresh AVS/CVV codes |
| `purchase()` | `PurchaseRequest` | `POST /api/v1/sales` | `TenderType` `Credit` or `Cash` (`ExpectedPayments` 1 vs 5) |
| `purchase()` (hosted) | `PurchaseRequest` | `POST /api/v1/HostedPaymentPageRequests` | A `HostedPayment` instrument switches to the hosted page and returns a `RedirectChallenge` — see below |
| `authorize()` | `AuthorizeRequest` | `POST /api/v1/authonlys` | `/authonlys` rejects cash — a `Cash` instrument is transparently routed to `purchase()` |
| `capture()` | `CaptureRequest` | `POST /api/v1/Captures` | Full amount only; the nested `sale` envelope is unwrapped because the **sale** GUID (not the capture GUID) is what later Returns/Void expect |
| `capture()` (partial) | `PartialCaptureRequest` | void + `POST /api/v1/sales` | See below |
| `refund()` | `RefundRequest` | `POST /api/v1/returns` | Unsettled sale (422 `Sale has not been settled`) falls back to `POST /api/v1/void` with the same `SaleGuid` + `Amount` |
| `retryRefund()` | `ReturnRetryRequest` | `POST /api/v1/returns` | `ReturnRetryCard` payload — redirects a previously **declined** Return onto another card (30-day window) |
| `void()` | `VoidRequest` | `POST /api/v1/void` | By `AuthOnlyGuid` |
| `issueVirtualCard()` | `IssueVirtualCardRequest` | `POST /api/v1/IssueCard` | Purchases API; see below |
| `updateVirtualCard()` | `UpdateVirtualCardRequest` | `PUT /api/v1/IssueCard/{guid}` | Only `AmountLimit` + `PurchaseType`; success = HTTP 200 without `error` body |
| `terminateVirtualCard()` | `TerminateCardRequest` | `POST /api/v1/TerminateCard/{cardGuid}` | |

Transport failures on the API call never throw from `sendData()` — every
request maps `GuzzleException` into a failed response carrying the message.
The lazy token grant is the exception: an authentication failure surfaces
as a `RuntimeException` from the client.

### Hosted payment page

`purchase()` with a
`Techork\PaymentService\Common\ValueObject\HostedPayment` instrument asks
ConnexPay for a hosted-page token instead of charging card data, and returns a
`RedirectChallenge` pointing at ConnexPay's own page. The buyer pays there; the
outcome arrives on the ordinary `sale.card.auth.*` webhook.

The request shape is **not** what the public reference implies, and was
established by probing the sandbox (pinned by a case in
`tests/Integration/ConnexPaySandboxTest.php`, and reproducible from the unit
test if ConnexPay ever changes it):

- `Sale.Amount` and `Sale.DeviceGuid` are the fields that get read. Putting the
  amount inside `ConnexpayTransaction` instead makes the API see 0 and reject it
  against a 0.5 minimum; omitting the device guid there makes it substitute a
  different device and fail the lookup.
- `Sale.RiskData` is mandatory for `Credit` / `GooglePay` / `ApplePay`, so the
  hosted flow requires a billing address and refuses without one.
- `Sale.ConnexpayTransaction` is required but only as a presence check — an
  empty object is accepted. Note the lower-case `p`, unlike
  `ConnexPayTransaction` on `/api/v1/sales`.
- `CancelUrl` exists and is honoured, so both URLs on the instrument map
  directly. `Expiration` is honoured as sent and defaults to the end of the
  following day, so the request sets 4 h explicitly.
- `TenderTypeOptions` defaults to `['Credit']`, which is what we send. `ACH`
  would additionally need `IncludeRiskAnalysis` and a `Customer` block.

**The response carries no sale guid** — only a temp token, because the sale does
not exist until somebody pays, and there is no endpoint to read the request back
(`GET` by token and by id both 404). So the gateway reference recorded at
redirect time is our own `OrderNumber` (the payment intent id), and the page URL
is derived from the `otherUrl` host in the response rather than hardcoded —
only the sandbox host is documented anywhere.

Correlation on the way back therefore cannot be by guid: the webhook is the
first time we see it. `Webhook\SaleCorrelation` tries the stored guid first and
falls back to the `orderNumber` on the sale message, which ConnexPay documents
as the "client provided transaction identifier" and which
`withOrderNumber()` filled with the intent id. A `orderNumber` that is not
shaped like one of our aggregate ids resolves to nothing — it is webhook input,
not a trusted key.

### Partial capture

ConnexPay [can only capture the full authorized amount](https://docs.connexpay.com/docs/auth-and-capture).
For a smaller amount `ConnexPayGateway::capture()` detects
`money < authorizedAmount` and dispatches `PartialCaptureRequest`, which
**voids the AuthOnly and runs a fresh sale** with the original instrument
(required — missing `instrument` throws). A capture above the authorized
amount throws `InvalidArgumentException`.

### OrderNumber & IncomingTransactionCode

- The caller's `clientUniqueId` is forwarded as `OrderNumber` on every
  endpoint that accepts it; synthetic `:capture` / `:cancel` idempotency
  suffixes are stripped (`ConnexPayRequestParameters::withOrderNumber()`).
  `OrderNumber` is the **only** Search/Sales filter ConnexPay honors —
  `SaleGuid` / `Guid` filters are silently ignored by that endpoint.
- The `IncomingTransactionCode` (the merchant-facing "acquirer id") exists
  only in the sale/capture response body. `ConnexPayResponse::getTransactionMetadata()`
  surfaces it as `incoming_transaction_code` so it gets persisted with the
  gateway reference; `issueVirtualCard()` prefers that stored value and only
  falls back to paging `Search/Sales` (capped at 20 pages) when absent.

### Requests & responses

- 3DS: a `ThreeDSResult` parameter is forwarded as `Card.ThreeDS`
  (`Cavv`, `Version`, `DirectoryServerTransactionID`, `AcsTransactionId`, `ECI`).
- `ConnexPayResponse` (base for all Sales-API responses): success =
  `wasProcessed`, reference = `guid`; detects a 3DS challenge
  (`threeDSecure.authenticationStatus === 'Challenge'`) and exposes it as a
  `ThreeDSChallenge`; maps scheme AVS/CVV letter codes to normalized
  `CheckResult`s via `ConnexPaySchemeChecks`.
- ConnexPay rejects non-ASCII `Customer` fields ("München"), so city names
  are transliterated to ASCII (ext-intl, iconv fallback).
- Virtual card issuance maps the domain `CardSpendCategory` to ConnexPay's
  2-digit `PurchaseType` via `PurchaseTypeBridge`; `CardBrand` accepts only
  Visa / Mastercard (anything else throws, `null` lets the issuer pick).
- `PurchaseType` restricts the card, it does not label it: a card issued as
  `04` (pay-TV) is declined at a filling station. ConnexPay's guidance is to
  pick the most restrictive code that fits, so the bridge never substitutes a
  narrower code than the domain category asked for.

## Webhooks

`ConnexPayWebhookSubscriber` (wired via composer `extra.laravel.webhook`)
registers kind `ConnexPay`:

- `SignatureVerifier` — ConnexPay authenticates deliveries with
  [HTTP Basic Auth](https://docs.connexpay.com/docs/client-vcc-decisioning)
  using the same `username`/`password` credential pair; empty credentials
  fail closed, comparison is constant-time.
- `EventParser` — reads the `eventType` discriminator and `guid`
  (unique per transaction, doubles as the idempotency key).

| Event | Handler | Effect |
| --- | --- | --- |
| `sale.card.auth.approved` | `SaleApprovedHandler` | Records the finalized processor fee on the PaymentIntent |
| `sale.card.auth.declined` | `SaleDeclinedHandler` | Records a gateway failure (dashboard-initiated declines) |
| `sale.card.auth.voided` | `SaleVoidedHandler` | Cancels the PaymentIntent (dashboard-initiated voids) |
| `purchase.card.auth.settled` | `PurchaseSettledHandler` | Records the settled fee on the virtual card |

Webhook payloads do **not** carry the fee — `HttpServiceFeeFetcher`
(`ServiceFeeFetcher` implementation) pulls `serviceFee` from
`Search/Sales` / `Search/Purchases` at handle time. The field's exact shape
is undocumented, so it is read defensively and a warning is logged when
absent; `null` maps to a Skipped outcome and relies on webhook redelivery.

## Testing

Unit tests are offline (Pest). `tests/Integration/ConnexPaySandboxTest.php`
runs live against the ConnexPay sandbox and is skipped unless
`CONNEXPAY_SANDBOX_USERNAME`, `CONNEXPAY_SANDBOX_PASSWORD` and
`CONNEXPAY_SANDBOX_DEVICE_GUID` are set.
