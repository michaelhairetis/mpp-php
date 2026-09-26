<?php

declare(strict_types=1);

namespace Mpp;

use Mpp\Exception\ParseException;

/**
 * RFC 8785 JSON Canonicalization Scheme.
 *
 * Challenge binding HMACs the wire bytes, so two implementations that serialize the same object
 * differently will not interoperate. Hence canonical output rather than plain json_encode.
 */
final class Jcs
{
    public static function encode(mixed $value): string
    {
        return self::serialize($value);
    }

    /**
     * Force object output. PHP cannot tell `{}` from `[]` once json_decode has run, and the
     * protocol's request, opaque and payload fields are always objects, so the caller says so.
     *
     * @param array<array-key, mixed>|object $value
     */
    public static function encodeObject(array|object $value): string
    {
        return self::object(is_object($value) ? get_object_vars($value) : $value);
    }

    private static function serialize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::number($value),
            is_string($value) => self::string($value),
            is_object($value) => self::object(get_object_vars($value)),
            is_array($value) => array_is_list($value) ? self::list($value) : self::object($value),
            default => throw new ParseException('cannot canonicalize ' . get_debug_type($value)),
        };
    }

    /**
     * @param list<mixed> $items
     */
    private static function list(array $items): string
    {
        return '[' . implode(',', array_map(self::serialize(...), $items)) . ']';
    }

    /**
     * @param array<array-key, mixed> $members
     */
    private static function object(array $members): string
    {
        $keys = array_map(strval(...), array_keys($members));
        usort($keys, self::compareKeys(...));

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = self::string($key) . ':' . self::serialize($members[$key]);
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Keys sort by UTF-16 code unit. Byte order over UTF-8 disagrees for anything above the BMP,
     * where surrogates sort below U+E000..U+FFFF, so compare the UTF-16 encoding directly.
     */
    private static function compareKeys(string $a, string $b): int
    {
        return strcmp(self::utf16($a), self::utf16($b));
    }

    private static function utf16(string $s): string
    {
        return mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
    }

    private static function string(string $s): string
    {
        $encoded = json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $encoded;
    }

    /**
     * ECMAScript Number::toString. Monetary amounts in MPP payloads are strings, so this mainly
     * guards against a caller passing a float by accident.
     */
    private static function number(float $n): string
    {
        if (is_nan($n) || is_infinite($n)) {
            throw new ParseException('NaN and Infinity are not representable in JSON');
        }

        if ($n === floor($n) && abs($n) < 1e21) {
            return number_format($n, 0, '.', '');
        }

        $repr = var_export($n, true);

        return str_contains($repr, 'E') ? str_replace('E', 'e', $repr) : $repr;
    }
}
