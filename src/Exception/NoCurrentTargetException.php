<?php

declare(strict_types=1);

namespace Horde\Hordectl\Exception;

use RuntimeException;

/**
 * Exception thrown when no current target is set in configuration
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class NoCurrentTargetException extends RuntimeException {}
