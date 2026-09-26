<?php

declare(strict_types=1);

namespace Mpp;

use DateTimeImmutable;
use Mpp\Exception\ParseException;

/**
 * The `Payment-Receipt` header. Only ever issued on a 2xx. Failures carry a fresh challenge and
 * RFC 9457 problem details instead, so there is no failure status to represent here.
 */
final class Receipt
{
    public const HEADER = 'Payment-Receipt';
    public const STATUS_SUCCESS = 'success';

    /**
     * @param array<string, mixed> $extra method-defined additional fields
     */
    public function __construct(
        public readonly string $method,
        public readonly string $reference,
        public readonly string $timestamp,
        /** @var array<string, mixed> */
        public readonly array $extra = [],
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function now(string $method, string $reference, array $extra = []): self
    {
        return new self($method, $reference, Timestamps::format(new DateTimeImmutable()), $extra);
    }

    public static function fromHeader(string $value): self
    {
        $decoded = Base64Url::decodeJsonObject(trim($value));

        foreach (['status', 'method', 'timestamp', 'reference'] as $required) {
            if (!isset($decoded[$required]) || !is_string($decoded[$required])) {
                throw new ParseException("receipt is missing \"{$required}\"");
            }
        }

        if ($decoded['status'] !== self::STATUS_SUCCESS) {
            throw new ParseException('receipt status must be "success"');
        }

        Timestamps::parse($decoded['timestamp']);

        $extra = $decoded;
        unset($extra['status'], $extra['method'], $extra['timestamp'], $extra['reference']);

        return new self($decoded['method'], $decoded['reference'], $decoded['timestamp'], $extra);
    }

    public function toHeader(): string
    {
        return Base64Url::encodeJson([
            'status' => self::STATUS_SUCCESS,
            'method' => $this->method,
            'timestamp' => $this->timestamp,
            'reference' => $this->reference,
            ...$this->extra,
        ]);
    }
}
