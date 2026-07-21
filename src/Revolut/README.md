# Revolut Business gateway

Issuing-only gateway for deploying **virtual cards** through the
[Revolut Business API](https://developer.revolut.com/docs/business/business-api).
Acquiring / tokenization operations are unsupported and throw
`UnsupportedOperationException`.

> There is **no Revolut Sandbox for virtual cards** — every card operation
> (create / update / terminate / sensitive-details) runs against Production
> (`https://b2b.revolut.com`).

## Authentication

Revolut uses OAuth 2.0 with a signed **JWT client assertion**
([docs](https://developer.revolut.com/docs/guides/manage-accounts/get-started/make-your-first-api-request)).
Every token exchange is authenticated by an RS256 JWT signed with your private
key; there are two exchanges:

| Grant | When | Performed by |
| --- | --- | --- |
| `authorization_code` | Once, at setup — mints the long-lived **refresh token** | `RevolutAuthenticator::exchangeAuthorizationCode()` (via the bootstrap script below) |
| `refresh_token` | Every process — mints a 40-minute **access token** | `RevolutClient` automatically, before the first API call |

### Stored credentials

The gateway is configured (`RevolutGateway::initialize()`) with:

| Credential | Meaning |
| --- | --- |
| `clientId` | Client id Revolut issues when you register the API certificate |
| `privateKey` | PEM private key whose public half is uploaded to Revolut (used to sign the JWT) |
| `issuer` | Domain of the OAuth redirect URL registered with Revolut (the JWT `iss` claim) |
| `refreshToken` | Long-lived refresh token obtained from the portal (see below) |

`clientId`, `privateKey` and `issuer` come straight from the portal setup. The
**`refreshToken` is the only credential you have to mint yourself.**

### Obtaining the refresh token (one-time, from the admin portal)

1. Generate an RSA key pair:
   ```bash
   openssl genrsa -out privatekey.pem 2048
   openssl rsa -in privatekey.pem -pubout -out publickey.pem
   ```
2. In the Revolut Business portal → **Settings → APIs**, add a certificate:
   upload `publickey.pem` and set an **OAuth redirect URI**. Revolut issues the
   `client id`. The redirect URI's domain is your `issuer`.
3. **Enable API access** for the app. The browser is redirected to your
   redirect URI with a short-lived `?code=...` (valid ~2 minutes).
4. Immediately exchange that code for the refresh token:
   ```bash
   php bin/revolut-obtain-refresh-token.php \
       --client-id=<client_id> \
       --issuer=<redirect-url-domain> \
       --private-key=privatekey.pem \
       --code=<authorization_code>
   ```
5. Store the printed `refresh_token` as the gateway's `refreshToken` credential,
   alongside `clientId`, `privateKey` and `issuer`.

After that the runtime never needs the portal again — `RevolutClient` refreshes
access tokens on its own from the stored refresh token.
