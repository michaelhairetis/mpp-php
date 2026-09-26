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
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9\-_]+$/', $value) !== 1) {
            throw new ParseException('value is not base64url without padding');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new ParseException('value is not valid base64url');
        }

        return $decoded;
    }

    /**
     * Encode an object as JCS JSON, then base64url. Used for `request`, `opaque` and credentials,
     * all of which are objects on the wire even when empty.
     *
     * @param array<array-key, mixed>|object $value
     */
    public static function encodeJson(array|object $value): string
    {
        return self::encode(Jcs::encodeObject($value));
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeJsonObject(string $value): array
    {
        $decoded = json_decode(self::decode($value), true, 64, JSON_THROW_ON_ERROR);

        // [] is how PHP represents an empty JSON object, so only a populated list is an error.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ParseException('expected a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
