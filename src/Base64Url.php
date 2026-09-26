<?php

declare(strict_types=1);

namespace Mpp;

use Mpp\Exception\ParseException;

/**
 * RFC 4648 section 5, padding omitted.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9\-_]+$/', $value) !== 1) {
            throw new ParseException('value is not base64url without padding');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new ParseException('value is not valid base64url');
        }

        return $decoded;
    }

    /**
     * Encode a value as JCS JSON, then base64url. Used for `request` and `opaque`.
     */
    public static function encodeJson(mixed $value): string
    {
        return self::encode(Jcs::encode($value));
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeJsonObject(string $value): array
    {
        $decoded = json_decode(self::decode($value), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ParseException('expected a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
