<?php

declare(strict_types=1);

namespace Mpp\Tests\Server;

use Mpp\ChallengeBinding;
use Mpp\Credential;
use Mpp\Exception\VerificationException;
use Mpp\Method\Tempo\TempoCharge;
use Mpp\Receipt;
use Mpp\Server\PaymentGate;
use Mpp\Server\Verifier;
use PHPUnit\Framework\TestCase;

final class PaymentGateTest extends TestCase
{
    private const ADDRESS = '0x742d35Cc6634c0532925a3b844bC9e7595F8fE00';

    public function testIssuesAChallengeItCanVerify(): void
    {
        $gate = $this->gate();
        $challenge = $gate->challenge($this->request());

        $credential = new Credential($challenge, $this->payload());

        self::assertSame('tempo', $gate->verify($credential->toHeader())->method);
    }

    public function testBindsTheBodyDigestWhenThereIsABody(): void
    {
        $gate = $this->gate();
        $challenge = $gate->challenge($this->request(), body: '{"q":"weather"}');
        $credential = new Credential($challenge, $this->payload());

        self::assertNotNull($challenge->digest);
        self::assertSame('tempo', $gate->verify($credential->toHeader(), '{"q":"weather"}')->method);

        $this->expectException(VerificationException::class);
        $gate->verify($credential->toHeader(), '{"q":"stocks"}');
    }

    public function testRejectsACredentialMintedAgainstAnotherSecret(): void
    {
        $foreign = new PaymentGate(new ChallengeBinding('other'), new TempoCharge(), $this->verifier(), 'api.example.com');
        $credential = new Credential($foreign->challenge($this->request()), $this->payload());

        $this->expectException(VerificationException::class);
        $this->gate()->verify($credential->toHeader());
    }

    public function testRejectsAnExpiredChallenge(): void
    {
        $gate = $this->gate(ttl: -60);
        $credential = new Credential($gate->challenge($this->request()), $this->payload());

        $this->expectException(VerificationException::class);
        $gate->verify($credential->toHeader());
    }

    public function testRejectsAChallengeIssuedForAnotherRealm(): void
    {
        $other = new PaymentGate(new ChallengeBinding('s'), new TempoCharge(), $this->verifier(), 'other.example.com');
        $credential = new Credential($other->challenge($this->request()), $this->payload());

        $this->expectException(VerificationException::class);
        $this->gate()->verify($credential->toHeader());
    }

    public function testRejectsAMalformedPayloadBeforeSettling(): void
    {
        $verifier = new class implements Verifier {
            public bool $called = false;

            public function verify(Credential $credential): Receipt
            {
                $this->called = true;

                return Receipt::now('tempo', '0xfeed');
            }
        };

        $gate = new PaymentGate(new ChallengeBinding('s'), new TempoCharge(), $verifier, 'api.example.com');
        $credential = new Credential($gate->challenge($this->request()), ['type' => 'nonsense']);

        try {
            $gate->verify($credential->toHeader());
            self::fail('expected rejection');
        } catch (VerificationException) {
            self::assertFalse($verifier->called, 'settlement must not be attempted for an invalid payload');
        }
    }

    public function testAltHeaderIsPropagatedToTheChallenge(): void
    {
        $gate = new PaymentGate(new ChallengeBinding('s'), new TempoCharge(), $this->verifier(), 'api.example.com', useAltHeader: true);

        self::assertSame('Payment-Authorization', $gate->credentialHeaderName());
        self::assertSame('Payment-Authorization', $gate->challenge($this->request())->credentialHeader());
    }

    private function gate(int $ttl = 300): PaymentGate
    {
        return new PaymentGate(new ChallengeBinding('s'), new TempoCharge(), $this->verifier(), 'api.example.com', ttlSeconds: $ttl);
    }

    private function verifier(): Verifier
    {
        return new class implements Verifier {
            public function verify(Credential $credential): Receipt
            {
                return Receipt::now('tempo', '0xfeed');
            }
        };
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return ['amount' => '1000000', 'currency' => self::ADDRESS, 'recipient' => self::ADDRESS];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['type' => 'hash', 'hash' => '0x' . str_repeat('a', 64)];
    }
}
