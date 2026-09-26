<?php

declare(strict_types=1);

namespace Mpp\Tests;

use Mpp\Base64Url;
use Mpp\Challenge;
use Mpp\ChallengeBinding;
use PHPUnit\Framework\TestCase;

/**
 * Vectors are from draft-httpauth-payment-01, "HMAC-SHA256 Test Vectors".
 */
final class ChallengeBindingTest extends TestCase
{
    private const SECRET = 'test-vector-secret';
    private const REQUEST = 'eyJhbW91bnQiOiIxMDAwMDAwIn0';
    private const OPAQUE = 'eyJwaSI6InBpXzEyMyJ9';

    public function testRequestEncodingMatchesTheVector(): void
    {
        self::assertSame(self::REQUEST, Base64Url::encodeJson(['amount' => '1000000']));
        self::assertSame(self::OPAQUE, Base64Url::encodeJson(['pi' => 'pi_123']));
    }

    public function testVectorWithoutHeaderOrOpaque(): void
    {
        self::assertSame(
            'X6v1eo7fJ76gAxqY0xN9Jd__4lUyDDYmriryOM-5FO4',
            $this->binding()->issue('api.example.com', 'tempo', 'charge', self::REQUEST)->id,
        );
    }

    public function testVectorWithHeader(): void
    {
        self::assertSame(
            'S91xi-OFGZPMs-j7GsX0FDpIkmCcZT1P9XyV58WNy_U',
            $this->binding()->issue('api.example.com', 'tempo', 'charge', self::REQUEST, header: Challenge::ALT_HEADER)->id,
        );
    }

    public function testVectorWithHeaderAndOpaque(): void
    {
        self::assertSame(
            'CJ4X1O4aTDmS59hfdhnhBtxIQjWDOf0bcrhsswwMOW8',
            $this->binding()->issue('api.example.com', 'tempo', 'charge', self::REQUEST, header: Challenge::ALT_HEADER, opaque: self::OPAQUE)->id,
        );
    }

    public function testVerifiesWhatItIssued(): void
    {
        $binding = $this->binding();

        self::assertTrue($binding->verify($binding->issue('api.example.com', 'tempo', 'charge', self::REQUEST)));
    }

    public function testRejectsTamperedParameters(): void
    {
        $binding = $this->binding();
        $issued = $binding->issue('api.example.com', 'tempo', 'charge', self::REQUEST);

        $tampered = new Challenge($issued->id, 'evil.example.com', 'tempo', 'charge', self::REQUEST);

        self::assertFalse($binding->verify($tampered));
    }

    /**
     * description is deliberately outside the binding, so changing it must not invalidate.
     */
    public function testDescriptionIsNotBound(): void
    {
        $binding = $this->binding();
        $issued = $binding->issue('api.example.com', 'tempo', 'charge', self::REQUEST, description: 'one');

        $relabelled = new Challenge($issued->id, 'api.example.com', 'tempo', 'charge', self::REQUEST, description: 'two');

        self::assertTrue($binding->verify($relabelled));
    }

    /**
     * Positional slots keep (expires, no digest) distinct from (no expires, digest).
     */
    public function testOptionalSlotsCannotCollide(): void
    {
        $binding = $this->binding();
        $withExpires = $binding->issue('r', 'tempo', 'charge', self::REQUEST, expires: '2030-01-01T00:00:00Z');
        $withDigest = $binding->issue('r', 'tempo', 'charge', self::REQUEST, digest: '2030-01-01T00:00:00Z');

        self::assertNotSame($withExpires->id, $withDigest->id);
    }

    public function testAnotherSecretDoesNotVerify(): void
    {
        $issued = $this->binding()->issue('api.example.com', 'tempo', 'charge', self::REQUEST);

        self::assertFalse((new ChallengeBinding('wrong'))->verify($issued));
    }

    private function binding(): ChallengeBinding
    {
        return new ChallengeBinding(self::SECRET);
    }
}
