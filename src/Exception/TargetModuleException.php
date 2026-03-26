<?php

declare(strict_types=1);

namespace Horde\Hordectl\Exception;

/**
 * Exception thrown when a target command fails
 *
 * Used to signal that a target module successfully claimed and attempted
 * to handle a command, but the command execution failed.
 */
class TargetModuleException extends ModuleException
{
}
