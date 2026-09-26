<?php

declare(strict_types=1);

namespace Mpp\Server;

use Mpp\Credential;
use Mpp\Receipt;

/**
 * Settles, or confirms settlement of, the payment a credential claims.
 *
 * This is the boundary where the SDK stops. Everything above it is protocol (parsing, binding,
 * expiry, digests) and can be checked offline. Confirming that a Tempo transaction actually
 * landed needs an RPC endpoint and a funded relationship, which belongs to the integrator.
 */
interface Verifier
{
    /**
     * @throws \Mpp\Exception\VerificationException when the payment is missing, short or unusable
     */
    public function verify(Credential $credential): Receipt;
}
