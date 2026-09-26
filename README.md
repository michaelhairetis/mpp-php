# mpp-php

PHP SDK for the [Machine Payments Protocol](https://mpp.dev) — pay for HTTP resources per request,
no account or API key set up in advance.

MPP is an HTTP authentication scheme ([`draft-ryan-httpauth-payment`](https://datatracker.ietf.org/doc/draft-ryan-httpauth-payment/)),
so this library is built like one: no framework dependency, PSR-7/15/18 throughout.

```
GET /report
  <- 402  WWW-Authenticate: Payment id="...", realm="api.example.com", method="tempo", intent="charge", request="..."
GET /report  Authorization: Payment <credential>
  -> 200  Payment-Receipt: <receipt>
```

## Install

```bash
composer require michaelhairetis/mpp
```

Requires PHP 8.2+.

## Selling

`PaymentGate` issues challenges and checks credentials. It does not settle — confirming a payment
landed needs chain access, so that sits behind `Verifier`, which you implement.

```php
use Mpp\ChallengeBinding;
use Mpp\Method\Tempo\TempoCharge;
use Mpp\Server\PaymentGate;

$gate = new PaymentGate(
    binding: new ChallengeBinding($_ENV['MPP_SECRET']),
    method: new TempoCharge(),
    verifier: $yourVerifier,
    realm: 'api.example.com',
);

$challenge = $gate->challenge([
    'amount' => '500000',                                        // base units
    'currency' => '0x20c0...',                                   // TIP-20 token
    'recipient' => '0x742d35Cc6634c0532925a3b844bC9e7595F8fE00',
]);

// 402 + WWW-Authenticate: $challenge->toHeader()
// then, on the retry:
$receipt = $gate->verify($request->getHeaderLine($gate->credentialHeaderName()));
```

Or drop `PaymentMiddleware` into a PSR-15 stack and return the price per request:

```php
$middleware = new PaymentMiddleware($gate, $responseFactory, function ($request) {
    return str_starts_with($request->getUri()->getPath(), '/paid/')
        ? ['amount' => '500000', 'currency' => $token, 'recipient' => $wallet]
        : null;
});
```

The verified `Credential` and `Receipt` land on the request as `mpp.credential` and `mpp.receipt`.

## Buying

`PaymentClient` wraps any PSR-18 client and answers 402s. Signing lives in your `Payer`.

```php
$http = new PaymentClient($yourPsr18Client, $yourPayer);
$response = $http->sendRequest($request);
$receipt = $http->receiptFrom($response);
```

It re-pays if the server re-challenges (up to `maxPayments`), skips expired challenges, and picks
between competing offers using the payer's own preference order rather than the server's.

## Challenge binding

Challenge `id`s are an HMAC over the parameters they commit to, so a server can verify an echoed
challenge without storing it. The construction follows the spec's recommended layout and reproduces
its published test vectors.

```php
$binding = new ChallengeBinding($secret);
$challenge = $binding->issue($realm, 'tempo', 'charge', $requestB64);
$binding->verify($challenge);
```

`description` is deliberately outside the binding — it is display only.

## What's here

| | |
|---|---|
| Core | challenge, credential, receipt, RFC 9110 auth-params, RFC 8785 JCS, RFC 9530 digests |
| Methods | `tempo` / `charge` |
| Transport | PSR-15 middleware, PSR-18 client decorator |

Sessions, subscriptions, other methods, and the MCP transport are not implemented yet.

## Development

Everything runs in a container; nothing is installed on the host.

```bash
make install    # build image, composer install
make test
make stan
make shell
make clean      # drop the image and vendor/
```

## License

Apache-2.0 or MIT, at your option.
