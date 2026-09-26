<?php

declare(strict_types=1);

namespace Mpp;

use Mpp\Exception\ParseException;

/**
 * RFC 9530 Content-Digest, restricted to sha-256. Format is `sha-256=:<base64>:`.
 */
final class ContentDigest
{
    public static function of(string $body): string
    {
        return 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
    }

    public static function matches(string $expected, string $body): bool
    {
        return hash_equals(self::normalize($expected), self::normalize(self::of($body)));
    }

    /**
     * Algorithm names are case-insensitive; the base64 payload is not.
     */
    private static function normalize(string $value): string
    {
        if (preg_match('/^\s*([A-Za-z0-9-]+)\s*=\s*:([A-Za-z0-9+\/=]+):\s*$/', $value, $m) !== 1) {
            throw new ParseException('malformed content digest');
        }

        if (strtolower($m[1]) !== 'sha-256') {
            throw new ParseException("unsupported digest algorithm \"{$m[1]}\"");
        }

        return 'sha-256=:' . $m[2] . ':';
    }
}
