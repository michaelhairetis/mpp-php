# mpp-php — design notes

PHP SDK for the Machine Payments Protocol. Core only: no framework bridges until the core is solid.

Spec: `draft-httpauth-payment-01` (IETF `draft-ryan-httpauth-payment`). Local copy at
`../mpp-specs/specs/core/`. Rendered at https://paymentauth.org/.

## What MPP actually is

An HTTP authentication scheme, not a custom header protocol. Server answers `402` with
`WWW-Authenticate: Payment ...`; client retries with `Authorization: Payment <base64url>`.
Everything else is layered on top of that. Build it like an auth library.

```
GET /resource
  <- 402  WWW-Authenticate: Payment id="...", realm="...", method="tempo", intent="charge", request="<b64u>"
GET /resource  Authorization: Payment <b64u({challenge, source?, payload})>
  -> 200  Payment-Receipt: <b64u({status, method, timestamp, reference})>
```

Spec is layered: **Core** (402 semantics, headers, registries) → **Intents** (charge, subscription)
→ **Methods** (tempo, stripe, evm, solana, card, lightning, stellar, xrpl, hedera, usdc,
nearintents) → **Extensions** (discovery, MCP transport).

## Wire surface the core must cover

**Challenge** — `WWW-Authenticate: Payment` with RFC 9110 auth-params.

| Param | Req | Notes |
|---|---|---|
| `id` | yes | non-empty after unescaping; bound via HMAC |
| `realm` | yes | protection space |
| `method` | yes | lowercase ASCII |
| `intent` | yes | IANA registry value |
| `request` | yes | base64url-nopad of JCS JSON, method-defined |
| `digest` | no | RFC 9530 content digest of the body |
| `expires` | no | RFC 3339 |
| `description` | no | display only, never trusted for verification |
| `header` | no | only legal value `Payment-Authorization` |
| `opaque` | no | base64url-nopad of JCS JSON, flat string map, echoed unchanged |

Unknown params are ignored.

**Credential** — `Payment <base64url-nopad>` of `{challenge, source?, payload}`. `challenge` echoes
the challenge params verbatim; `header` is echoed only if the challenge carried it.

**Receipt** — `Payment-Receipt: <base64url-nopad>` of `{status, method, timestamp, reference}`.
`status` is always `"success"`; never emitted on error responses. Failures use 402 + a fresh
challenge + RFC 9457 problem details.

## Primitives, in dependency order

1. `Base64Url` — RFC 4648 §5, no padding. Trivial, everything depends on it.
2. `Jcs` — RFC 8785 canonical JSON. Keys sorted by UTF-16 code unit, no insignificant whitespace,
   ECMAScript number formatting. Required for challenge binding to interoperate — different
   serialization orders produce different HMACs.
3. `AuthParams` — RFC 9110 §11 parser/formatter. token / quoted-string, BWS, OWS, comma-separated.
   The subtle one; see conformance notes below.
4. `ContentDigest` — RFC 9530, `sha-256=:<base64>:`.
5. `Timestamps` — RFC 3339 parse and expiry comparison.

Then `Challenge`, `Credential`, `Receipt`, the `PaymentMethod` interface, and server/client halves.

## Conformance — steal the bugs other SDKs already hit

`tempoxyz/mpp-tools` runs a conformance suite ("Agricola") against every SDK. Its open findings are
a free spec for what to get right on day one:

- **AGR-2026-103** — challenge auth-param names parsed case-sensitively. They are case-insensitive.
- **AGR-2026-102** — parser rejects canonical method identifiers containing digits or separators.
- **AGR-2026-104** — valid lowercase method identifiers with non-letter characters rejected.
- **AGR-2026-101** — challenge formatting emits non-Latin-1 text unusable as an HTTP header value.
- **AGR-2026-105/106/107** — automatic client: silently returns an unhandleable initial 402; handles
  only one retry; ignores payment preference ordering.
- **mpp-go #151** — repeated same-scheme `WWW-Authenticate` challenges become unparseable once
  folded into one header line.
- **mpp-tools #158** — duplicate-parameter detection misfires when a quoted value contains a comma.

Every one of these belongs in the test suite before the corresponding code is written.

## Package

- PHP 8.2+, `Mpp\` namespace, PSR-4.
- PSR-7 messages, PSR-18 client, PSR-15 server middleware. No framework dependency.
- Dual Apache-2.0 / MIT, matching the Tempo SDKs.
- `phpunit` + `phpstan` at max level.

```
src/
  Base64Url.php  Jcs.php  AuthParams.php  ContentDigest.php  Timestamps.php
  Challenge.php  Credential.php  Receipt.php
  Exception/
  Method/       PaymentMethod interface + per-method payload handling
  Intent/
  Server/       PSR-15 middleware, challenge issue + credential verify
  Client/       PSR-18 decorator, 402 -> pay -> retry
```

## v0.1 scope

Core wire format end to end, one method (`tempo`, charge intent), discovery emission. Enough for a
seller to gate a route and a buyer to pay it.

Out of scope for v0.1: sessions, subscriptions, non-Tempo methods, MCP transport, framework bridges.

## Status

Scaffolding. Nothing implemented yet.
