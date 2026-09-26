<?php

declare(strict_types=1);

namespace Mpp\Exception;

/**
 * Credential was well-formed but did not satisfy the challenge. Always answered with a fresh 402.
 */
final class VerificationException extends MppException
{
    public function __construct(string $message, public readonly string $problemType = 'about:blank')
    {
        parent::__construct($message);
    }
}
