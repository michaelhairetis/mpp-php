<?php

declare(strict_types=1);

namespace Mpp;

use Mpp\Exception\ParseException;

/**
 * A `WWW-Authenticate: Payment` challenge.
 *
 * `request` and `opaque` are held in their wire form (base64url of JCS JSON) because the binding
 * HMAC covers those exact bytes. Use the decode helpers to read them.
 */
final class Challenge
{
    public const SCHEME = 'Payment';
    public const ALT_HEADER = 'Payment-Authorization';

    public function __construct(
        public readonly string $id,
        public readonly string $realm,
        public readonly string $method,
        public readonly string $intent,
        public readonly string $request,
        public readonly ?string $digest = null,
        public readonly ?string $expires = null,
        public readonly ?string $description = null,
        public readonly ?string $header = null,
        public readonly ?string $opaque = null,
    ) {
        if ($id === '') {
            throw new ParseException('challenge id must not be empty');
        }
        if ($realm === '') {
            throw new ParseException('challenge realm must not be empty');
        }
        if (preg_match('/^[a-z0-9._~-]+$/', $method) !== 1) {
            throw new ParseException("invalid payment method identifier \"{$method}\"");
        }
        if ($intent === '') {
            throw new ParseException('challenge intent must not be empty');
        }
        if ($header !== null && $header !== self::ALT_HEADER) {
            throw new ParseException('the only permitted header value is ' . self::ALT_HEADER);
        }
    }

    /**
     * Parse the first Payment challenge out of one or more WWW-Authenticate values.
     *
     * @param string|array<int, string> $value
     */
    public static function fromHeader(string|array $value): self
    {
        foreach (AuthParams::parseChallenges($value) as $challenge) {
            if (strcasecmp($challenge['scheme'], self::SCHEME) !== 0) {
                continue;
            }

            return self::fromParams($challenge['params']);
        }

        throw new ParseException('no Payment challenge found');
    }

    /**
     * @param array<string, string> $params
     */
    public static function fromParams(array $params): self
    {
        foreach (['id', 'realm', 'method', 'intent', 'request'] as $required) {
            if (($params[$required] ?? '') === '') {
                throw new ParseException("challenge is missing required parameter \"{$required}\"");
            }
        }

        return new self(
            id: $params['id'],
            realm: $params['realm'],
            method: $params['method'],
            intent: $params['intent'],
            request: $params['request'],
            digest: $params['digest'] ?? null,
            expires: $params['expires'] ?? null,
            description: $params['description'] ?? null,
            header: $params['header'] ?? null,
            opaque: $params['opaque'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toParams(): array
    {
        $params = [
            'id' => $this->id,
            'realm' => $this->realm,
            'method' => $this->method,
            'intent' => $this->intent,
            'request' => $this->request,
        ];

        foreach (['digest', 'expires', 'description', 'header', 'opaque'] as $optional) {
            if ($this->{$optional} !== null) {
                $params[$optional] = $this->{$optional};
            }
        }

        return $params;
    }

    public function toHeader(): string
    {
        return AuthParams::format(self::SCHEME, $this->toParams());
    }

    /**
     * The field the client must put the credential in.
     */
    public function credentialHeader(): string
    {
        return $this->header ?? 'Authorization';
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeRequest(): array
    {
        return Base64Url::decodeJsonObject($this->request);
    }

    /**
     * @return array<string, string>
     */
    public function decodeOpaque(): array
    {
        if ($this->opaque === null) {
            return [];
        }

        $decoded = Base64Url::decodeJsonObject($this->opaque);
        foreach ($decoded as $v) {
            if (!is_string($v)) {
                throw new ParseException('opaque must be a flat string map');
            }
        }

        /** @var array<string, string> $decoded */
        return $decoded;
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expires !== null && Timestamps::isPast($this->expires, $now);
    }
}
