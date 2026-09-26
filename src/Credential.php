<?php

declare(strict_types=1);

namespace Mpp;

use Mpp\Exception\ParseException;

/**
 * The `Payment <base64url>` credential a client sends on the retry.
 *
 * The echoed challenge must survive the round trip byte for byte — the server re-derives the
 * binding from it, so anything altered in transit fails verification.
 */
final class Credential
{
    /**
     * @param array<string, mixed> $payload method-specific payment proof
     */
    public function __construct(
        public readonly Challenge $challenge,
        public readonly array $payload,
        public readonly ?string $source = null,
    ) {
    }

    /**
     * @param string $value the full field value, including the "Payment " prefix
     */
    public static function fromHeader(string $value): self
    {
        $parts = preg_split('/\s+/', trim($value), 2);
        if ($parts === false || count($parts) !== 2 || strcasecmp($parts[0], Challenge::SCHEME) !== 0) {
            throw new ParseException('credential must be a Payment credential');
        }

        $decoded = Base64Url::decodeJsonObject($parts[1]);

        if (!isset($decoded['challenge']) || !is_array($decoded['challenge'])) {
            throw new ParseException('credential is missing the challenge object');
        }
        if (!isset($decoded['payload']) || !is_array($decoded['payload'])) {
            throw new ParseException('credential is missing the payload object');
        }

        $params = [];
        foreach ($decoded['challenge'] as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                throw new ParseException('echoed challenge parameters must be strings');
            }
            $params[$k] = $v;
        }

        $source = $decoded['source'] ?? null;
        if ($source !== null && !is_string($source)) {
            throw new ParseException('source must be a string');
        }

        /** @var array<string, mixed> $payload */
        $payload = $decoded['payload'];

        return new self(Challenge::fromParams($params), $payload, $source);
    }

    public function toHeader(): string
    {
        $body = ['challenge' => $this->challenge->toParams()];
        if ($this->source !== null) {
            $body['source'] = $this->source;
        }
        $body['payload'] = $this->payload;

        return Challenge::SCHEME . ' ' . Base64Url::encodeJson($body);
    }

    /**
     * The field this credential belongs in, as selected by its challenge.
     */
    public function headerName(): string
    {
        return $this->challenge->credentialHeader();
    }
}
