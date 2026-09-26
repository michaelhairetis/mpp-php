<?php

declare(strict_types=1);

namespace Mpp;

use DateTimeImmutable;
use Mpp\Exception\ParseException;
use Throwable;

/**
 * RFC 3339 date-times, as used by `expires` and receipt timestamps.
 */
final class Timestamps
{
    public static function parse(string $value): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw new ParseException("not an RFC 3339 date-time: \"{$value}\"");
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            throw new ParseException("not an RFC 3339 date-time: \"{$value}\"", 0, $e);
        }
    }

    public static function format(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function isPast(string $value, ?int $now = null): bool
    {
        return self::parse($value)->getTimestamp() <= ($now ?? time());
    }
}
