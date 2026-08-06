<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core\Exceptions;

/**
 * Thrown when an IR execution request fails at the database level or
 * when the IR payload itself is invalid / unsupported.
 *
 * Mirrors the implicit RuntimeError raised inside Python's _execute_ir.
 */
class IrExecutionException extends \RuntimeException
{
}
