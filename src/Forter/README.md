# Forter fraud-screening provider

`techork/payment-service-forter` implements Common's
`FraudScreeningProvider` port against the
[Forter](https://docs.forter.com/) Order Validation API: it POSTs each card
payment to `/orders/{orderId}` at the `PRE_AUTHORIZATION` step and translates
Forter's decision into a `FraudVerdict`.

## Layout

| Class | Role |
| --- | --- |
| `ForterFraudScreeningProvider` | The port implementation — maps the request, calls the API, translates the response |
| `ForterRequestMapper` | `FraudScreeningRequest` → Forter order JSON body |
| `ForterClient` | Guzzle transport for `POST /orders/{orderId}` (auth headers, JSON decoding) |
| `ForterHttpClientInterface` | Transport seam — implemented by `ForterClient`, faked in tests |

## Configuration

`ForterClient` takes its configuration in the constructor:

| Argument | Meaning |
| --- | --- |
| `secretKey` | Forter API secret key. Sent as the HTTP Basic username with an empty password (`base64(secretKey:)`) |
| `baseUrl` | API base URL **including the version path**; defaults to `https://api.forter.com/v2` (production) |
| `siteId` | Optional; when set, sent as the `x-forter-siteid` header |
| `http` | Optional `GuzzleHttp\ClientInterface`, for tests or an outbound proxy |

Every request also carries the `api-version: 2.2` header
(`ForterClient::API_VERSION`).

## Decision mapping

Forter's `action` field is matched case-insensitively:

| Forter `action` | `FraudDecision` |
| --- | --- |
| `approve` | `Approve` |
| `decline` | `Decline` |
| `not reviewed` / `not_reviewed` | `NotReviewed` |
| anything else / missing | `Errored` |

The verdict carries Forter's `reasonCode` when present, and uses the response
`transaction` id as its `reference`, falling back to the request's fraud
reference.

**Fail-soft:** `screen()` never throws. Any transport failure (timeout,
connection error) or unrecognizable response yields an `Errored` verdict, so
the decision layer (`RiskDecisionPort`) can apply its fail-open / fail-closed
policy uniformly. A `Decline` is the provider's opinion, not the final action
— the decision layer combines it with operator rules to decide on 3DS step-up.
In the composed system, screening only runs for cardholder-initiated payments
(the Laravel `FraudScreeningCreatePort` decorator skips MIT).

## Payload notes

- Only the PCI-safe card summary crosses the wire: BIN, last four, expiration
  and name on card — never the PAN or CVV (asserted by tests).
- The amount is emitted under `amountUSD` as a decimal string
  (`DecimalMoneyFormatter`). The mapper does **no FX conversion**; the caller
  must pass the amount in the currency the Forter account is configured for.
- Fixed fields: `orderType: WEB`, `authorizationStep: PRE_AUTHORIZATION`, and
  a single synthetic `NON_TANGIBLE` / `DIGITAL` cart item named "Payment"
  (mirrors the legacy backoffice mapper).
- `connectionInformation` carries `customerIP` and `userAgent`; the request's
  `deviceToken` becomes `forterTokenCookie` when present and is omitted
  otherwise. Optional billing fields (`address2`, `region`, `phone`, `email`)
  are likewise omitted when absent.
- `orderId` is the caller-generated fraud reference from
  `FraudScreeningRequest::$reference` (URL-encoded into the path).

## Testing

Pure unit tests (Pest) against a fake `ForterHttpClientInterface` — no Forter
credentials or network access required.
