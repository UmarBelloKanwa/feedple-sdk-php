<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core\Exceptions;

/**
 * Thrown when schema synchronisation to the Feedple API fails.
 *
 * Mirrors Python's SchemaSyncError.
 */
class SchemaSyncException extends \RuntimeException
{
}
