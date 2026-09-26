<?php

declare(strict_types=1);

namespace Mpp;

use Mpp\Exception\ParseException;

/**
 * RFC 9110 section 11.2 challenge parsing and formatting.
 *
 * Fiddlier than it looks: parameter names are case-insensitive, quoted values may contain commas,
 * and several challenges can end up folded onto one header line.
 */
final class AuthParams
{
    private const TOKEN_CHARS = "!#$%&'*+-.^_`|~";

    /**
     * @param string|array<int, string> $header one or more WWW-Authenticate values
     *
     * @return list<array{scheme: string, params: array<string, string>}>
     */
    public static function parseChallenges(string|array $header): array
    {
        $out = [];
        foreach ((array) $header as $line) {
            foreach (self::parseLine($line) as $challenge) {
                $out[] = $challenge;
            }
        }

        return $out;
    }

    /**
     * @return list<array{scheme: string, params: array<string, string>}>
     */
    private static function parseLine(string $line): array
    {
        $len = strlen($line);
        $i = 0;
        $challenges = [];

        while ($i < $len) {
            self::skipCommasAndSpace($line, $i);
            if ($i >= $len) {
                break;
            }

            $token = self::readToken($line, $i);
            if ($token === '') {
                throw new ParseException('expected a token at offset ' . $i);
            }

            $save = $i;
            self::skipSpace($line, $i);

            // A token not followed by "=" starts a new challenge rather than naming a parameter.
            if ($i >= $len || $line[$i] !== '=') {
                $i = $save;
                $challenges[] = ['scheme' => $token, 'params' => []];
                continue;
            }

            if ($challenges === []) {
                throw new ParseException('parameter before any auth-scheme');
            }

            ++$i;
            self::skipSpace($line, $i);
            $value = ($i < $len && $line[$i] === '"') ? self::readQuoted($line, $i) : self::readToken($line, $i);

            $name = strtolower($token);
            $last = count($challenges) - 1;
            if (array_key_exists($name, $challenges[$last]['params'])) {
                throw new ParseException("duplicate auth-param \"{$name}\"");
            }
            $challenges[$last]['params'][$name] = $value;
        }

        return $challenges;
    }

    /**
     * @param array<string, string> $params
     */
    public static function format(string $scheme, array $params): string
    {
        $parts = [];
        foreach ($params as $name => $value) {
            $parts[] = strtolower($name) . '=' . self::encodeValue($value);
        }

        $line = $parts === [] ? $scheme : $scheme . ' ' . implode(', ', $parts);

        // Header field values are ISO-8859-1; anything outside it cannot travel on the wire.
        if (preg_match('/[^\x20-\x7E\xA0-\xFF]/', $line) === 1) {
            throw new ParseException('challenge contains characters that are not valid in a header value');
        }

        return $line;
    }

    private static function encodeValue(string $value): string
    {
        if ($value !== '' && strspn($value, self::TOKEN_CHARS . 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') === strlen($value)) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private static function readToken(string $s, int &$i): string
    {
        $start = $i;
        $i += strspn($s, self::TOKEN_CHARS . 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $i);

        return substr($s, $start, $i - $start);
    }

    private static function readQuoted(string $s, int &$i): string
    {
        $len = strlen($s);
        ++$i;
        $out = '';

        while ($i < $len) {
            $c = $s[$i];
            if ($c === '"') {
                ++$i;

                return $out;
            }
            if ($c === '\\' && $i + 1 < $len) {
                $out .= $s[$i + 1];
                $i += 2;
                continue;
            }
            $out .= $c;
            ++$i;
        }

        throw new ParseException('unterminated quoted-string');
    }

    private static function skipSpace(string $s, int &$i): void
    {
        $i += strspn($s, " \t", $i);
    }

    private static function skipCommasAndSpace(string $s, int &$i): void
    {
        $i += strspn($s, " \t,", $i);
    }
}
