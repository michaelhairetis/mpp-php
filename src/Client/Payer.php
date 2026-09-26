<?php

declare(strict_types=1);

namespace Mpp\Client;

use Mpp\Challenge;

/**
 * Turns a challenge into a payment payload. Implementations hold the keys and the RPC access;
 * the SDK holds neither.
 */
interface Payer
{
    /**
     * Method identifiers this payer can settle, most preferred first. Used to pick between
     * competing challenges.
     *
     * @return list<string>
     */
    public function supports(): array;

    /**
     * @return array<string, mixed> the credential `payload`
     *
     * @throws \Mpp\Exception\MppException if the challenge cannot be paid
     */
    public function pay(Challenge $challenge): array;

    /**
     * Payer identifier, ideally a DID. Null omits `source` from the credential.
     */
    public function source(): ?string;
}
