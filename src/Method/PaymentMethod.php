<?php

declare(strict_types=1);

namespace Mpp\Method;

/**
 * A payment method knows the shape of its own `request` and `payload` objects. It deliberately
 * does not settle anything, because that needs chain or processor access. That belongs behind
 * Mpp\Server\Verifier.
 */
interface PaymentMethod
{
    /**
     * Lowercase method identifier as it appears in the challenge.
     */
    public function id(): string;

    /**
     * @param array<string, mixed> $request decoded challenge `request`
     *
     * @throws \Mpp\Exception\ParseException
     */
    public function validateRequest(array $request): void;

    /**
     * @param array<string, mixed> $payload decoded credential `payload`
     *
     * @throws \Mpp\Exception\ParseException
     */
    public function validatePayload(array $payload): void;
}
