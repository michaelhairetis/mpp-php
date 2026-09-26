<?php

declare(strict_types=1);

namespace Mpp\Tests;

use Mpp\AuthParams;
use Mpp\Exception\ParseException;
use PHPUnit\Framework\TestCase;

/**
 * Cases here mirror open conformance findings against the other MPP SDKs, so the PHP one does
 * not repeat them.
 */
final class AuthParamsTest extends TestCase
{
    public function testParsesTokenAndQuotedValues(): void
    {
        $c = AuthParams::parseChallenges('Payment id="abc", realm="api.example.com", method=tempo');

        self::assertCount(1, $c);
        self::assertSame('Payment', $c[0]['scheme']);
        self::assertSame(['id' => 'abc', 'realm' => 'api.example.com', 'method' => 'tempo'], $c[0]['params']);
    }

    /** AGR-2026-103 */
    public function testParameterNamesAreCaseInsensitive(): void
    {
        $c = AuthParams::parseChallenges('Payment ID="abc", ReAlM="r"');

        self::assertSame(['id' => 'abc', 'realm' => 'r'], $c[0]['params']);
    }

    /** mpp-tools #158 */
    public function testCommaInsideQuotedValueDoesNotSplitParameters(): void
    {
        $c = AuthParams::parseChallenges('Payment id="a,b", realm="r"');

        self::assertSame('a,b', $c[0]['params']['id']);
        self::assertSame('r', $c[0]['params']['realm']);
    }

    /** mpp-go #151 */
    public function testFoldedChallengesOnOneLine(): void
    {
        $c = AuthParams::parseChallenges('Payment id="1", Payment id="2"');

        self::assertCount(2, $c);
        self::assertSame('2', $c[1]['params']['id']);
    }

    public function testSeparateHeaderValues(): void
    {
        $c = AuthParams::parseChallenges(['Payment id="1"', 'Bearer realm="x"']);

        self::assertCount(2, $c);
        self::assertSame('Bearer', $c[1]['scheme']);
    }

    /**
     * AGR-2026-102 and AGR-2026-104 — method identifiers are not letters-only.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('methodIdentifiers')]
    public function testMethodIdentifiersWithNonLetters(string $id): void
    {
        $c = AuthParams::parseChallenges("Payment method={$id}");

        self::assertSame($id, $c[0]['params']['method']);
    }

    /** @return iterable<array{string}> */
    public static function methodIdentifiers(): iterable
    {
        yield ['x402v2'];
        yield ['near-intents'];
        yield ['card.sandbox'];
    }

    public function testUnescapesQuotedPairs(): void
    {
        $c = AuthParams::parseChallenges('Payment id="a\"b\\\\c"');

        self::assertSame('a"b\\c', $c[0]['params']['id']);
    }

    public function testRejectsDuplicateParameter(): void
    {
        $this->expectException(ParseException::class);
        AuthParams::parseChallenges('Payment id="1", id="2"');
    }

    public function testRejectsUnterminatedQuotedString(): void
    {
        $this->expectException(ParseException::class);
        AuthParams::parseChallenges('Payment id="oops');
    }

    public function testFormatsTokensBareAndEverythingElseQuoted(): void
    {
        self::assertSame('Payment method=tempo', AuthParams::format('Payment', ['method' => 'tempo']));
        self::assertSame('Payment id="a,b"', AuthParams::format('Payment', ['id' => 'a,b']));
        self::assertSame('Payment id="a\"b"', AuthParams::format('Payment', ['id' => 'a"b']));
    }

    /** AGR-2026-101 — header field values are ISO-8859-1. */
    public function testRejectsValuesThatCannotTravelInAHeader(): void
    {
        $this->expectException(ParseException::class);
        AuthParams::format('Payment', ['description' => 'café ☕']);
    }

    public function testRoundTrip(): void
    {
        $params = ['id' => 'x7Tg', 'realm' => 'api.example.com', 'method' => 'tempo', 'intent' => 'charge'];

        self::assertSame($params, AuthParams::parseChallenges(AuthParams::format('Payment', $params))[0]['params']);
    }
}
