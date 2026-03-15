<?php

declare(strict_types=1);

namespace Horde\Hordectl\Exception;

use RuntimeException;

/**
 * Exception thrown when a requested target is not found in configuration
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class TargetNotFoundException extends RuntimeException {}
