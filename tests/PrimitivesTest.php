<?php

declare(strict_types=1);

namespace Mpp\Tests;

use Mpp\Base64Url;
use Mpp\ContentDigest;
use Mpp\Exception\ParseException;
use Mpp\Jcs;
use Mpp\Timestamps;
use PHPUnit\Framework\TestCase;

final class PrimitivesTest extends TestCase
{
    public function testBase64UrlRoundTripsWithoutPadding(): void
    {
        self::assertSame('hello world', Base64Url::decode(Base64Url::encode('hello world')));
        self::assertStringNotContainsString('=', Base64Url::encode('abcd'));
        self::assertSame('__4', Base64Url::encode("\xff\xfe"));
    }

    public function testBase64UrlRejectsPaddedInput(): void
    {
        $this->expectException(ParseException::class);
        Base64Url::decode('YWJjZA==');
    }

    public function testBase64UrlRejectsJsonArrays(): void
    {
        $this->expectException(ParseException::class);
        Base64Url::decodeJsonObject(Base64Url::encode('[1,2]'));
    }

    public function testJcsSortsKeysAndOmitsWhitespace(): void
    {
        self::assertSame('{"a":2,"b":1}', Jcs::encode(['b' => 1, 'a' => 2]));
        self::assertSame('{"z":{"c":2,"d":1}}', Jcs::encode(['z' => ['d' => 1, 'c' => 2]]));
        self::assertSame('{"x":[1,2]}', Jcs::encode(['x' => [1, 2]]));
        self::assertSame('[true,false,null]', Jcs::encode([true, false, null]));
    }

    public function testJcsLeavesUnicodeAndSlashesUnescaped(): void
    {
        self::assertSame('{"k":"é"}', Jcs::encode(['k' => 'é']));
        self::assertSame('{"u":"a/b"}', Jcs::encode(['u' => 'a/b']));
    }

    public function testJcsFormatsIntegralFloatsWithoutDecimalPoint(): void
    {
        self::assertSame('{"n":1}', Jcs::encode(['n' => 1.0]));
    }

    /**
     * Keys sort by UTF-16 code unit, not UTF-8 byte. A supplementary character encodes to a
     * surrogate pair starting at 0xD800, so it sorts before U+FB00 despite the UTF-8 bytes
     * running the other way.
     */
    public function testJcsSortsKeysByUtf16CodeUnit(): void
    {
        $supplementary = json_decode('"𐀀"');
        $bmp = json_decode('"ﬀ"');

        self::assertStringStartsWith('{"' . $supplementary, Jcs::encode([$bmp => 2, $supplementary => 1]));
    }

    public function testJcsRejectsNonFiniteNumbers(): void
    {
        $this->expectException(ParseException::class);
        Jcs::encode(['n' => NAN]);
    }

    public function testContentDigestMatchesRfc9530Shape(): void
    {
        self::assertSame('sha-256=:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=:', ContentDigest::of(''));
        self::assertTrue(ContentDigest::matches(ContentDigest::of('hello'), 'hello'));
        self::assertFalse(ContentDigest::matches(ContentDigest::of('hello'), 'hell0'));
    }

    public function testContentDigestAlgorithmNameIsCaseInsensitive(): void
    {
        self::assertTrue(ContentDigest::matches('SHA-256=:' . base64_encode(hash('sha256', 'x', true)) . ':', 'x'));
    }

    public function testContentDigestRejectsOtherAlgorithms(): void
    {
        $this->expectException(ParseException::class);
        ContentDigest::matches('md5=:abc:', 'x');
    }

    public function testTimestampComparison(): void
    {
        self::assertTrue(Timestamps::isPast('2020-01-01T00:00:00Z'));
        self::assertFalse(Timestamps::isPast('2099-01-01T00:00:00Z'));
        self::assertSame(1768471500, Timestamps::parse('2026-01-15T12:05:00+02:00')->getTimestamp());
    }

    public function testTimestampRejectsDateWithoutTime(): void
    {
        $this->expectException(ParseException::class);
        Timestamps::parse('2026-01-15');
    }
}
