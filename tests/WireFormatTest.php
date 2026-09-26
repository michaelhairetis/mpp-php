<?php

declare(strict_types=1);

namespace Mpp\Tests;

use Mpp\Base64Url;
use Mpp\Challenge;
use Mpp\ChallengeBinding;
use Mpp\Credential;
use Mpp\Exception\ParseException;
use Mpp\Receipt;
use PHPUnit\Framework\TestCase;

final class WireFormatTest extends TestCase
{
    private const REQUEST = 'eyJhbW91bnQiOiIxMDAwMDAwIn0';

    public function testChallengeSurvivesAHeaderRoundTrip(): void
    {
        $binding = new ChallengeBinding('s');
        $issued = $binding->issue('api.example.com', 'tempo', 'charge', self::REQUEST, expires: '2099-01-01T00:00:00Z');

        $parsed = Challenge::fromHeader($issued->toHeader());

        self::assertTrue($binding->verify($parsed));
        self::assertSame(['amount' => '1000000'], $parsed->decodeRequest());
    }

    public function testCredentialFieldFollowsTheChallenge(): void
    {
        $plain = new Challenge('i', 'r', 'tempo', 'charge', self::REQUEST);
        $alt = new Challenge('i', 'r', 'tempo', 'charge', self::REQUEST, header: Challenge::ALT_HEADER);

        self::assertSame('Authorization', $plain->credentialHeader());
        self::assertSame('Payment-Authorization', $alt->credentialHeader());
    }

    public function testCredentialRoundTrip(): void
    {
        $challenge = (new ChallengeBinding('s'))->issue('r', 'tempo', 'charge', self::REQUEST);
        $credential = new Credential($challenge, ['type' => 'hash', 'hash' => '0x' . str_repeat('a', 64)], 'did:pkh:eip155:4217:0xdead');

        $parsed = Credential::fromHeader($credential->toHeader());

        // Canonicalization sorts object keys, so compare by key rather than by order.
        self::assertEquals($credential->payload, $parsed->payload);
        self::assertSame('did:pkh:eip155:4217:0xdead', $parsed->source);
        self::assertTrue((new ChallengeBinding('s'))->verify($parsed->challenge));
    }

    /**
     * The spec forbids echoing `header` when the challenge omitted it.
     */
    public function testAbsentHeaderIsNotEchoed(): void
    {
        $challenge = new Challenge('i', 'r', 'tempo', 'charge', self::REQUEST);

        self::assertArrayNotHasKey('header', (new Credential($challenge, []))->challenge->toParams());
    }

    public function testCredentialRejectsAnotherScheme(): void
    {
        $this->expectException(ParseException::class);
        Credential::fromHeader('Bearer abc');
    }

    public function testChallengeRejectsInvalidConstruction(): void
    {
        $cases = [
            fn () => new Challenge('', 'r', 'tempo', 'charge', self::REQUEST),
            fn () => new Challenge('i', '', 'tempo', 'charge', self::REQUEST),
            fn () => new Challenge('i', 'r', 'Tempo', 'charge', self::REQUEST),
            fn () => new Challenge('i', 'r', 'tempo', 'charge', self::REQUEST, header: 'X-Nope'),
            fn () => Challenge::fromParams(['id' => 'a', 'realm' => 'b']),
        ];

        foreach ($cases as $i => $case) {
            try {
                $case();
                self::fail("case {$i} should have been rejected");
            } catch (ParseException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOpaqueMustBeAFlatStringMap(): void
    {
        $challenge = new Challenge('i', 'r', 'tempo', 'charge', self::REQUEST, opaque: Base64Url::encodeJson(['n' => 1]));

        $this->expectException(ParseException::class);
        $challenge->decodeOpaque();
    }

    public function testReceiptRoundTripKeepsMethodExtensions(): void
    {
        $receipt = new Receipt('tempo', '0xfeed', '2026-01-01T00:00:00Z', ['block' => '42']);

        $parsed = Receipt::fromHeader($receipt->toHeader());

        self::assertSame('tempo', $parsed->method);
        self::assertSame('0xfeed', $parsed->reference);
        self::assertSame(['block' => '42'], $parsed->extra);
    }

    public function testReceiptRejectsNonSuccessStatus(): void
    {
        $encoded = Base64Url::encodeJson([
            'status' => 'failed',
            'method' => 'tempo',
            'timestamp' => '2026-01-01T00:00:00Z',
            'reference' => 'r',
        ]);

        $this->expectException(ParseException::class);
        Receipt::fromHeader($encoded);
    }
}
