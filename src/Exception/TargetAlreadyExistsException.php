<?php

declare(strict_types=1);

namespace Horde\Hordectl\Exception;

use RuntimeException;

/**
 * Exception thrown when attempting to create a target that already exists
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class TargetAlreadyExistsException extends RuntimeException {}
