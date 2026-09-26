<?php

declare(strict_types=1);

namespace Mpp\Method\Tempo;

use Mpp\Exception\ParseException;
use Mpp\Method\PaymentMethod;

/**
 * Tempo `charge` — draft-tempo-charge-00.
 */
final class TempoCharge implements PaymentMethod
{
    public const ID = 'tempo';
    public const INTENT = 'charge';
    public const DEFAULT_CHAIN_ID = 42431;

    /** Payload discriminators. */
    public const TYPE_TRANSACTION = 'transaction';
    public const TYPE_HASH = 'hash';
    public const TYPE_PROOF = 'proof';

    public function id(): string
    {
        return self::ID;
    }

    /**
     * @param array<string, mixed> $request
     */
    public function validateRequest(array $request): void
    {
        foreach (['amount', 'currency', 'recipient'] as $field) {
            if (!isset($request[$field]) || !is_string($request[$field]) || $request[$field] === '') {
                throw new ParseException("tempo request is missing \"{$field}\"");
            }
        }

        if (preg_match('/^\d+$/', (string) $request['amount']) !== 1) {
            throw new ParseException('amount must be base units as a decimal string');
        }

        self::assertAddress((string) $request['currency'], 'currency');
        self::assertAddress((string) $request['recipient'], 'recipient');

        $details = $request['methodDetails'] ?? [];
        if (!is_array($details)) {
            throw new ParseException('methodDetails must be an object');
        }

        if (isset($details['memo'])) {
            self::assertBytes32((string) $details['memo']);
        }

        foreach (self::splits($details) as $i => $split) {
            if (!is_array($split) || !isset($split['amount'], $split['recipient'])) {
                throw new ParseException("split {$i} needs amount and recipient");
            }
            if (preg_match('/^\d+$/', (string) $split['amount']) !== 1) {
                throw new ParseException("split {$i} amount must be base units");
            }
            self::assertAddress((string) $split['recipient'], "split {$i} recipient");
            if (isset($split['memo'])) {
                self::assertBytes32((string) $split['memo']);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function validatePayload(array $payload): void
    {
        $type = $payload['type'] ?? null;

        match ($type) {
            self::TYPE_TRANSACTION => self::assertHex($payload['signature'] ?? null, 'signature'),
            self::TYPE_HASH => self::assertTxHash($payload['hash'] ?? null),
            self::TYPE_PROOF => self::assertProof($payload),
            default => throw new ParseException('payload type must be transaction, hash or proof'),
        };
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<int, mixed>
     */
    private static function splits(array $details): array
    {
        $splits = $details['splits'] ?? [];
        if (!is_array($splits)) {
            throw new ParseException('splits must be an array');
        }

        return array_values($splits);
    }

    private static function assertAddress(string $value, string $field): void
    {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $value) !== 1) {
            throw new ParseException("{$field} must be a 20-byte hex address");
        }
    }

    private static function assertBytes32(string $value): void
    {
        if (preg_match('/^0x[0-9a-fA-F]{64}$/', $value) !== 1) {
            throw new ParseException('memo must be a bytes32 hex value');
        }
    }

    private static function assertTxHash(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^0x[0-9a-fA-F]{64}$/', $value) !== 1) {
            throw new ParseException('hash must be a 32-byte transaction hash');
        }
    }

    private static function assertHex(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^0x[0-9a-fA-F]+$/', $value) !== 1) {
            throw new ParseException("{$field} must be a 0x-prefixed hex string");
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function assertProof(array $payload): void
    {
        self::assertHex($payload['signature'] ?? null, 'proof signature');
    }
}
