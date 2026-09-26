<?php

declare(strict_types=1);

namespace Mpp\Exception;

/**
 * The client could not complete a payment exchange, and retrying will not help.
 */
final class ClientException extends MppException
{
}
