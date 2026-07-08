<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Exception;

/**
 * Exception thrown when a test command fails
 *
 * Future enhancement: Could track HTTP status, API errors vs test failures, etc.
 */
class TestModuleException extends ModuleException {}
