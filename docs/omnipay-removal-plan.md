# Removing omnipay/common — migration plan (deferred to 2.0)

**Status:** accepted, deferred until the 2.0 release.
**Decided:** 2026-07-29.
**Why:** the project uses `omnipay/common` v3.5.1 purely as a base-class library (~10% of it):
the parameter bag, `initialize()` credential hydration, the `createRequest()` parameter merge,
the `send() = sendData(getData())` lifecycle, and `validate()`. Everything Omnipay is actually
valuable for — HTTP transport, the gateway-driver ecosystem, `CreditCard`/money/redirect/webhook
machinery — is bypassed: all five drivers (Stripe, Nuvei, ConnexPay, Paynet, Revolut) are
in-house and do their own transport via stripe-php, the Nuvei SDK, or Guzzle. In exchange we pay
for a dead PSR-18 discovery run on every gateway construction, constructor boilerplate in ~28
test files, and three vendor packages (incl. the `php-http/discovery` install-time plugin) that
exist for nothing.

The removal is a **breaking change** (request constructor signatures, response types), hence 2.0.

## Constraints discovered by the audit

- The one cross-package coupling point is `src/Gateway/src/Contract/Gateway.php:14`
  (`interface Gateway extends Omnipay\Common\GatewayInterface`). Because of it the migration is
  atomic: the Gateway package moves first and all five drivers + the Laravel bridge flip in the
  same release. No incremental per-package path exists.
- The domain is already clean: `src/Domain` and `src/Common` have zero Omnipay imports, and the
  domain-facing port `Contract/PaymentGatewayInterface` is Omnipay-free. Every webhook subsystem
  is Omnipay-free too. Only the adapter skeleton changes.
- Blast radius: ~70 production files (`AbstractRequest` ×38, `AbstractResponse` ×18,
  `AbstractGateway` ×5), ~30 test files, ~36 request classes, ~20 response classes.
- Estimated effort: 3–5 engineer-days, ~350 lines of new dependency-free code.

## Stage 0 — characterization tests (safe, do first)

Pin current Omnipay semantics with tests that must stay green against the replacement:

- `Helper::initialize`/`camelCase`: `device_guid` → `setDeviceGuid()`, `CLIENT_ID` → `setClientId()`
  (underscore segments are lowercased first), keys without underscores pass through unchanged,
  unknown keys are silently ignored. **Highest-risk item**: Revolut
  (`RevolutGateway.php:194-201`) and ConnexPay (`ConnexPayGateway.php:93-100`) rely on this
  translation for DB-sourced credentials — a regression breaks credential loading silently.
- `initialize()` **resets** the parameter bag (does not merge) — `LaravelGatewayFactory.php:47-53`
  re-initializes via `$gateway->initialize($gateway->getParameters())` and depends on it.
- `AbstractGateway::createRequest()`: `array_replace($this->getParameters(), $parameters)` —
  call-site options override gateway parameters.
- `validate()`: `isset` semantics (null counts as missing), message `The %s parameter is required`.
- The post-`send()` mutation guard on `AbstractRequest::setParameter`.

## Stage 1 — in-house mini-framework in src/Gateway (~300 lines, coexists with Omnipay)

- `Contract/GatewayInterface`, `Contract/RequestInterface`, `Contract/ResponseInterface` — only
  what is consumed: `initialize/getParameters/getName/getDefaultParameters`; `send()/getData()`;
  `isSuccessful()/getTransactionReference()/getMessage()/getData()`.
- `Concern/HasParameters` trait — plain array instead of Symfony ParameterBag; `initialize()`
  with reset + camelCase dispatch (bit-identical `Helper` port); `validate()`; own
  `InvalidRequestException`.
- `Message/BaseRequest` — **no-arg constructor** (kills the `new PsrClient, new HttpRequest`
  boilerplate in ~28 test files), `send() = sendData(getData())`, post-send guard.
- `Message/BaseResponse` — `(request, data)` constructor + default accessors.
- `BaseGateway` — public `set/getParameter`, `initialize()` seeded by `getDefaultParameters()`,
  `createRequest($class, $params)` → `new $class()` + the same `array_replace` merge. **No HTTP
  client, no `createFromGlobals()`** — the dead discovery tax disappears, along with the
  empty-credentials guard in `NuveiGateway.php:122-128`.
- `Support/Luhn` (~20 lines: `validateLuhn` + last-4 masking) for the log sanitizer.

## Stage 2 — flip the core

- `Contract/Gateway.php` extends the in-house interface; return types become the in-house
  `RequestInterface`. Recommended at the same time: add
  `authorize/purchase/capture/refund/void/retryRefund/createCard/updateVirtualCard` to the
  contract — all five gateways already implement them (some as throw-only stubs), and the router
  stops duck-typing (`PaymentGatewayRouter.php:342,405` currently rely on `catch (Throwable)`).
- `GatewayFactory` drops the Omnipay parent; own registry `all()/replace()/register()` (~15
  lines) preserving the API (called by `GatewayServiceProvider.php:152`).
- `InstrumentParameters`: `@mixin` retargets to `BaseRequest`; `PaymentGatewayRouter` imports the
  in-house `ResponseInterface`.

## Stage 3 — mechanical sweep of the five drivers

Swap `extends AbstractGateway/AbstractRequest/AbstractResponse` → `Base*`, clean imports:
Stripe (1 gateway + 10 requests + 9 responses), ConnexPay (1+12+11), Nuvei (1+10+4), Paynet,
Revolut. Tests: drop the constructor boilerplate (~28 files), retarget
`InvalidRequestException` assertions (~5 files).

## Stage 4 — Laravel bridge

- Delete the dead `Omnipay::setFactory` + import (`GatewayServiceProvider.php:21,153` — nothing
  repo-wide consumes the facade).
- `CardNumberSanitizer.php:35,40`: replace `Helper::validateLuhn` / `CreditCard::getNumberMasked`
  with `Support/Luhn`.
- Optional: make `discoverGateways` read gateway names from composer `extra` metadata instead of
  instantiating every gateway class (`GatewayServiceProvider.php:264`).

## Stage 5 — composer cleanup

- Remove `omnipay/common` from: root, `src/Gateway`, `src/Paynet`, `src/Revolut`, `src/Laravel`,
  and (added 2026-07-29 as interim hygiene) `src/Stripe`, `src/Nuvei`, `src/ConnexPay`.
- Remove `php-http/guzzle7-adapter` (root, ConnexPay — ConnexPay declares `guzzlehttp/guzzle`
  directly instead), `php-http/httplug` (root, Gateway), and all nine
  `allow-plugins: php-http/discovery` entries. Check whether `symfony/http-client` in
  `src/Gateway` is still needed (it was an Omnipay virtual-package satisfier). `nyholm/psr7`
  stays — the webhook PSR-7 bridge uses it.
- `composer update`: `php-http/message`, `clue/stream-filter`, and the `php-http/discovery`
  install-time plugin leave the vendor tree (smaller supply-chain surface on a PCI-adjacent
  codebase).

## Stage 6 — verification

- `grep -ri omnipay src/` → zero hits.
- Full pest suite + per-package suites; integration `ConnexPaySandboxTest` / `RevolutLiveTest`
  as the final net (needs sandbox credentials).
- `bin/split.sh` dry-run to confirm split packages carry correct manifests.

## Risks

1. `Helper::initialize` fidelity for DB-sourced credentials — covered by Stage 0.
2. The change is atomic across all packages — one PR series, one release; the monorepo `replace`
   section makes the lockstep natural.
3. Breaking for external consumers (constructor signatures, response types) — release as 2.0.

## Follow-up (separate PR, cosmetic)

Rename `OmnipayCreatePort`/`OmnipayCapturePort`/`OmnipayCancelPort`/`OmnipayRefundPort` in
`src/Laravel/src/Port` — they contain no Omnipay code; the prefix is legacy naming.

## Interim hygiene already done (2026-07-29, pre-2.0)

Per-package composer.json manifests now declare all directly-imported dependencies (omnipay,
money, guzzle, psr/*, eventsauce, …), constraint divergences with the root were aligned
(Laravel: `omnipay ^3.0`→`^3.5`, `symfony/serializer`+`property-*` `^7.0`→`^7.0 || ^8.0`), and
dead imports were removed from `src/Stripe/src/AuthorizeRequest.php`, so `bin/split.sh` produces
installable packages while Omnipay is still in place.
