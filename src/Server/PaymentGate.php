<?php

declare(strict_types=1);

namespace Mpp\Server;

use DateTimeImmutable;
use Mpp\Base64Url;
use Mpp\Challenge;
use Mpp\ChallengeBinding;
use Mpp\ContentDigest;
use Mpp\Credential;
use Mpp\Exception\MppException;
use Mpp\Exception\VerificationException;
use Mpp\Method\PaymentMethod;
use Mpp\Receipt;
use Mpp\Timestamps;

/**
 * Issues challenges and checks credentials. Transport-free so it can be driven from middleware,
 * a framework controller, or a test.
 */
final class PaymentGate
{
    public function __construct(
        private readonly ChallengeBinding $binding,
        private readonly PaymentMethod $method,
        private readonly Verifier $verifier,
        private readonly string $realm,
        private readonly string $intent = 'charge',
        private readonly int $ttlSeconds = 300,
        private readonly bool $useAltHeader = false,
    ) {
    }

    /**
     * @param array<string, mixed>  $request method-specific payment request
     * @param array<string, string> $opaque  server correlation data, echoed back untouched
     */
    public function challenge(
        array $request,
        ?string $body = null,
        array $opaque = [],
        ?string $description = null,
        ?DateTimeImmutable $now = null,
    ): Challenge {
        $this->method->validateRequest($request);

        return $this->binding->issue(
            realm: $this->realm,
            method: $this->method->id(),
            intent: $this->intent,
            request: Base64Url::encodeJson($request),
            digest: $body !== null ? ContentDigest::of($body) : null,
            expires: Timestamps::format(($now ?? new DateTimeImmutable())->modify("+{$this->ttlSeconds} seconds")),
            description: $description,
            header: $this->useAltHeader ? Challenge::ALT_HEADER : null,
            opaque: $opaque === [] ? null : Base64Url::encodeJson($opaque),
        );
    }

    /**
     * Protocol checks first, settlement last, so an expired or tampered credential never
     * reaches the chain.
     *
     * @param string $headerValue the raw Authorization or Payment-Authorization value
     */
    public function verify(string $headerValue, ?string $body = null): Receipt
    {
        try {
            $credential = Credential::fromHeader($headerValue);
        } catch (MppException $e) {
            throw new VerificationException('malformed credential: ' . $e->getMessage());
        }

        $challenge = $credential->challenge;

        if (!$this->binding->verify($challenge)) {
            throw new VerificationException('challenge binding does not match');
        }
        if ($challenge->realm !== $this->realm) {
            throw new VerificationException('challenge was issued for another realm');
        }
        if ($challenge->method !== $this->method->id() || $challenge->intent !== $this->intent) {
            throw new VerificationException('challenge method or intent does not match this resource');
        }
        if ($challenge->isExpired()) {
            throw new VerificationException('challenge has expired');
        }
        if ($challenge->digest !== null && !ContentDigest::matches($challenge->digest, $body ?? '')) {
            throw new VerificationException('request body does not match the bound digest');
        }

        try {
            $this->method->validatePayload($credential->payload);
        } catch (MppException $e) {
            throw new VerificationException('invalid payload: ' . $e->getMessage());
        }

        return $this->verifier->verify($credential);
    }

    public function credentialHeaderName(): string
    {
        return $this->useAltHeader ? Challenge::ALT_HEADER : 'Authorization';
    }
}
