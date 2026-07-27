# Neutrino BIN / IP intelligence provider

`techork/payment-service-neutrino` implements the Common risk-intelligence
ports against the [Neutrino API](https://www.neutrinoapi.com): BIN lookups for
card facts and IP lookups for geolocation / reputation. The results feed the
fraud rule engine; both lookups are **fail-soft** — any transport error or
empty response yields `null` instead of an exception, so a provider outage
degrades rules gracefully rather than blocking the payment flow.

## Classes

| Class | Role |
| --- | --- |
| `NeutrinoClient` | Guzzle transport. `POST`s form-encoded requests to `https://neutrinoapi.net` and decodes the JSON response |
| `NeutrinoHttpClientInterface` | Transport abstraction (`request(string $endpoint, array $params): array`); lets tests substitute a canned client |
| `NeutrinoCardIntelligenceProvider` | Implements `Common\Contract\CardIntelligenceProvider` via the `bin-lookup` endpoint |
| `NeutrinoIpIntelligenceProvider` | Implements `Common\Contract\IpIntelligenceProvider` via the `ip-info` endpoint |

## Configuration

`NeutrinoClient` takes its credentials in the constructor:

| Argument | Meaning |
| --- | --- |
| `userId` | Neutrino account user id — sent as the `user-id` form parameter on every call |
| `apiKey` | API key — sent as the `api-key` form parameter on every call |
| `baseUrl` | Optional; defaults to `NeutrinoClient::BASE_URL` (`https://neutrinoapi.net`) |
| `http` | Optional pre-built Guzzle `ClientInterface` (a default one is created otherwise) |

There is no header-based auth: credentials ride along as form parameters,
matching the legacy backoffice integration.

```php
$client = new NeutrinoClient(userId: '...', apiKey: '...');

$cards = new NeutrinoCardIntelligenceProvider($client);
$ips   = new NeutrinoIpIntelligenceProvider($client);

$cardIntel = $cards->lookupBin('411111', ip: '203.0.113.7'); // ?CardIntelligence
$ipIntel   = $ips->lookupIp('203.0.113.7');                  // ?IpIntelligence
```

## Response mapping

`lookupBin($bin, $ip = null)` calls `bin-lookup` with `bin-number` (and
`customer-ip` when an IP is given) and maps to `CardIntelligence`:

| Neutrino key | `CardIntelligence` field | Notes |
| --- | --- | --- |
| `country-code` | `issuerCountry` | `null` when missing, empty or not a valid `Country` code |
| `is-prepaid` | `isPrepaid` | defaults to `false` when absent |
| `is-commercial` | `isCommercial` | defaults to `false` when absent |
| — | `funding` | always `CardFunding::Unknown` — the mapper reads no funding type from the response (`card-type` is ignored) |

`lookupIp($ip)` calls `ip-info` with `ip` and maps to `IpIntelligence`:

| Neutrino key | `IpIntelligence` field | Notes |
| --- | --- | --- |
| `country-code` | `country` | same `Country` validation as above |
| `is-proxy` (fallback `is-hosting`) | `isProxy` | defaults to `false` |
| `is-vpn` | `isVpn` | defaults to `false` |
| `hostname` (fallback `host-domain`) | `hostDomain` | `null` when absent |

## Caveats

- The mapped response keys follow the Neutrino v3 API but have not been
  verified against a live response yet — re-check them when real credentials
  are wired. Proxy/VPN signals may require the separate `ip-probe` endpoint on
  some plans.
- Caching is deliberately out of scope: per the Common contracts, it belongs in
  a decorating implementation in the consuming application.

## Testing

Pest unit tests only — no real credentials needed. `tests/Pest.php` provides
`fakeNeutrinoClient()`, an in-memory `NeutrinoHttpClientInterface` returning
per-endpoint canned responses (or throwing), used to cover the mapping,
defaulting and fail-soft paths.
