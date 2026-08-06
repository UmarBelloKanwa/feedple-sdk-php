<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core\Exceptions;

/**
 * Thrown when the WebSocket authentication handshake fails.
 *
 * Mirrors the PermissionError raised in Python's _authenticate()
 * when the server returns an auth.error message.
 */
class AuthException extends \RuntimeException
{
}
