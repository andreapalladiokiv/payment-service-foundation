# Common — shared kernel

`techork/payment-service-common` is the dependency-light kernel every other
`payment-service-*` package builds on: the ports (contracts) that gateway,
fraud and intelligence packages implement, and the value objects exchanged
across package boundaries. No framework, no HTTP client, no money library —
only `ramsey/uuid`, `giggsey/libphonenumber-for-php` and `symfony/intl`.

## Contracts (`src/Contract/`)

| Interface | Role | Implemented by |
| --- | --- | --- |
| `EncryptInterface` / `DecryptInterface` | Opaque string encryption used by `Number` and `Cvc` to hold PAN/CVC | the consuming application |
| `PaymentInstrument` + `PaymentInstrumentVisitor<T>` | Sealed instrument hierarchy; visitor dispatch plus `toPayload()` / `fromPayload()` round-trip | this package (below) |
| `Challenge` / `ChallengeVisitor<T>` | Interim state: the buyer's browser must complete an external action (ACS, hosted page) | `ThreeDSChallenge`, `RedirectChallenge` |
| `ChallengeResult` / `ChallengeResultVisitor<T>` | Terminal artefact of a completed challenge | `ThreeDSResult`, `RedirectResult` |

## Payment instruments

All implement `PaymentInstrument`; `type()` is the payload discriminator.

| Type | Class | Notes |
| --- | --- | --- |
| `card` | `CreditCard` | `Number` + `Expiration` + `Holder` + `Cvc`, optional `Address` and AVS/CVC `CheckResult`s. `isValid()` = not expired |
| `cash` | `Cash` | Marker, always valid |
| `token` | `Token` | `TokenId` + wrapped instrument + `ExpiresAt`; valid while unexpired and the inner instrument is valid |
| `payment_method` | `PaymentMethod` | `PaymentMethodId` + wrapped instrument + `BillingAddress` |
| `hosted` | `HostedPayment` | Hosted-page flow marker carrying `successUrl` / `cancelUrl` |

`PaymentInstrumentFactory::fromPayload()` rehydrates any instrument from its
`type` key (throws `InvalidArgumentException` on unknown types).
`CardSummaryExtractor::from()` projects any card-bearing instrument — unwrapping
`Token` / `PaymentMethod` — to the PCI-safe `CardSummary` (6-digit BIN, last
four, brand, expiration, holder), or `null` for cash / hosted.

## PCI handling

- `Number` keeps `first6` / `last4` / `brand` in the clear; the full PAN exists
  only encrypted (`Number::fromNumber($pan, EncryptInterface)`) and is read back
  with `getNumber(DecryptInterface)`. `jsonSerialize()` / `__debugInfo()` never
  expose it, and `CreditCard::toPayload()` carries only first6/last4/brand.
- `Cvc` stores the encrypted CVC and **drops it on PHP serialization**
  (`__serialize()` returns `[]`): saga state persisted between transitions
  rehydrates a CVC-less object, per PCI DSS 3.3.1 (no SAD retention after
  authorization). `jsonSerialize()` returns `'***'`, `__toString()` `''`.
- `CardBrand::fromNumber()` detects the network from the **full PAN** — the
  patterns need more than the BIN to disambiguate overlapping ranges — and
  throws `RuntimeException` when no pattern matches.

## PII shredding

The `#[Pii(stub)]` attribute marks properties/parameters holding PII with the
type-stable placeholder that replaces the value once shredded. Stubs live in
`ShreddingStubs`, chosen from formally reserved ranges so no real input collides:

| Constant | Value |
| --- | --- |
| `EMAIL` | `redacted@redacted.invalid` (RFC 2606 `.invalid` TLD) |
| `PHONE` | `+12025550100` (NANPA fictional range) |
| `NAME` / `CITY` / `POSTAL_CODE` | `[REDACTED]` |
| `ADDRESS_LINE` | `[REDACTED ADDRESS]` |
| `COUNTRY` | `ZZ` (user-assigned ISO code; `Country` whitelists it before the ICU check) |

`ShreddingStubs::RESERVED_EMAIL_DOMAINS` and `::PHONE_FICTION_REGEX` are input
validation helpers to keep stub-shaped values out of real submissions.

## Request origin

- `ConnectionContext` — validated `IpAddress`, user-agent, optional
  `deviceToken` (front-end fraud SDK fingerprint, e.g. Forter's cookie token).
  Where a request came from is ordinary transaction metadata, so it lives here
  rather than with any one risk vendor: the firewall requests in
  `payment-service-domain` carry it, and the Forter adapter reads it.

Vendor-specific risk contracts and value objects are **not** here. A screening
provider's request and verdict live in `payment-service-forter`, and the BIN and
IP intelligence contracts in `payment-service-neutrino`, because each is produced
and consumed inside its own package. What the firewall consumes is a
`FactSupplier` (see `payment-service-firewall`), so swapping vendors needs no
shared contract in this kernel.

## Challenges and 3DS

Interim challenges: `ThreeDSChallenge` (`acsUrl` + `creq` for direct MPI, or
`clientSecret` for gateway SDKs like Stripe.js) and `RedirectChallenge`
(`url` + `formFields` the browser POSTs to reach a hosted page). Terminal
results: `ThreeDSResult` (`ThreeDSStatus` Y/A/N/U/R, `authenticationValue`,
`ECICode`, DS/ACS transaction ids, `ThreeDSVersion`) — the liability-shift
evidence forwarded to acquiring gateways — and `RedirectResult`, which carries
only the transaction id because the outcome arrives via webhook.

## Supporting value objects

`Country` (normalizes alpha-3 / numeric to alpha-2, validated via Symfony
Intl), `State` (hard-coded code lists for AU / CA / IN / NZ / GB / US;
free-form when constructed without a country), `Email`, `PhoneNumber`
(libphonenumber, prints E.164), `BillingAddress` (with `toArray` /
`fromArray`), `Expiration` (month precision; a card is usable through the end
of its expiry month), `ExpiresAt` (ATOM-formatted), `CheckResult`
(`pass` / `fail` / `unavailable` / `unchecked` — distinguishes a real AVS/CVC
signal from "no information"), and `UuidValueObject` (UUIDv7 base for
`TokenId`, `PaymentMethodId`).

## Testing

Pest unit tests. `tests/Unit/InternalImportsResolveTest.php` is bundle-wide:
it recursively scans every PHP file under `<root>/src/*/src/` and asserts every internal
`use Techork\PaymentService\...` import resolves through the autoloader,
catching references to never-committed classes anywhere in the monorepo.
