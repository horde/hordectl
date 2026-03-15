<?php

/**
 * Setup autoloading for the tests.
 *
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Hordectl
 * @subpackage UnitTests
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

// Add PSR-4 namespace mapping for Horde\Hordectl to the Horde test autoloader
if (class_exists('Horde\Test\Autoload')) {
    Horde\Test\Autoload::addPrefix('Horde/Hordectl', dirname(__DIR__) . '/src');
}

// Add vendor lib directories to include path for old-style Horde_* classes
// Note: PHPUnit can now mock PSR-4 classes like Horde\Yaml\Dumper directly
$vendorLibDirs = [
    dirname(__DIR__) . '/vendor/horde/yaml/lib',
];

foreach ($vendorLibDirs as $dir) {
    if (is_dir($dir)) {
        set_include_path($dir . PATH_SEPARATOR . get_include_path());
    }
}
