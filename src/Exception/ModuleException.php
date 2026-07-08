<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Exception;

use RuntimeException;

/**
 * Exception thrown when a module command fails
 *
 * Used to signal that a module successfully claimed and attempted
 * to handle a command, but the command execution failed.
 */
class ModuleException extends RuntimeException {}
