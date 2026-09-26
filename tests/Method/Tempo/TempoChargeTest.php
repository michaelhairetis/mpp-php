<?php

declare(strict_types=1);

namespace Mpp\Tests\Method\Tempo;

use Mpp\Exception\ParseException;
use Mpp\Method\Tempo\TempoCharge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TempoChargeTest extends TestCase
{
    private const ADDRESS = '0x742d35Cc6634c0532925a3b844bC9e7595F8fE00';
    private const BYTES32 = '0x0000000000000000000000000000000000000000000000000000000000000001';

    public function testAcceptsAMinimalRequest(): void
    {
        $this->expectNotToPerformAssertions();

        (new TempoCharge())->validateRequest([
            'amount' => '1000000',
            'currency' => self::ADDRESS,
            'recipient' => self::ADDRESS,
        ]);
    }

    public function testAcceptsSplitsAndMemo(): void
    {
        $this->expectNotToPerformAssertions();

        (new TempoCharge())->validateRequest([
            'amount' => '1000000',
            'currency' => self::ADDRESS,
            'recipient' => self::ADDRESS,
            'methodDetails' => [
                'chainId' => TempoCharge::DEFAULT_CHAIN_ID,
                'memo' => self::BYTES32,
                'splits' => [
                    ['amount' => '250000', 'recipient' => self::ADDRESS, 'memo' => self::BYTES32],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $request
     */
    #[DataProvider('badRequests')]
    public function testRejectsMalformedRequests(array $request): void
    {
        $this->expectException(ParseException::class);
        (new TempoCharge())->validateRequest($request);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badRequests(): iterable
    {
        $valid = ['amount' => '1000000', 'currency' => self::ADDRESS, 'recipient' => self::ADDRESS];

        yield 'no amount' => [['currency' => self::ADDRESS, 'recipient' => self::ADDRESS]];
        yield 'decimal amount' => [[...$valid, 'amount' => '1.5']];
        yield 'amount not a string' => [[...$valid, 'amount' => 1000000]];
        yield 'short address' => [[...$valid, 'recipient' => '0xdead']];
        yield 'memo not bytes32' => [[...$valid, 'methodDetails' => ['memo' => '0xdead']]];
        yield 'split without recipient' => [[...$valid, 'methodDetails' => ['splits' => [['amount' => '1']]]]];
        yield 'methodDetails not an object' => [[...$valid, 'methodDetails' => 'nope']];
    }

    public function testAcceptsEachPayloadType(): void
    {
        $this->expectNotToPerformAssertions();
        $method = new TempoCharge();

        $method->validatePayload(['type' => 'transaction', 'signature' => '0xdeadbeef']);
        $method->validatePayload(['type' => 'hash', 'hash' => '0x' . str_repeat('a', 64)]);
        $method->validatePayload(['type' => 'proof', 'signature' => '0xdeadbeef']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('badPayloads')]
    public function testRejectsMalformedPayloads(array $payload): void
    {
        $this->expectException(ParseException::class);
        (new TempoCharge())->validatePayload($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badPayloads(): iterable
    {
        yield 'unknown type' => [['type' => 'wire']];
        yield 'missing type' => [['signature' => '0xab']];
        yield 'short hash' => [['type' => 'hash', 'hash' => '0xabc']];
        yield 'signature not hex' => [['type' => 'transaction', 'signature' => 'deadbeef']];
    }
}
