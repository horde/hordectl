<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl;

use RuntimeException;

/**
 * Helper class for composer operations
 *
 * @author Ralf Lang <lang@b1-systems.de>
 */
class ComposerHelper
{
    /**
     * Detect composer binary location
     *
     * Checks common locations for composer installation
     *
     * @return string Path to composer binary
     * @throws RuntimeException if composer not found
     */
    public function detectComposerBin(): string
    {
        $candidates = [
            'composer', // Check PATH first
            dirname(__DIR__) . '/vendor/bin/composer',
            '/usr/bin/composer',
            '/usr/bin/composer2',
            '/usr/local/bin/composer',
            '/usr/local/bin/composer2',
            '/usr/local/bin/composer.phar',
        ];

        foreach ($candidates as $candidatePath) {
            // For 'composer' without path, use which/where to check PATH
            if ($candidatePath === 'composer') {
                $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
                $output = [];
                $exitCode = 0;
                exec("$which composer 2>/dev/null", $output, $exitCode);
                if ($exitCode === 0 && !empty($output[0])) {
                    return $output[0];
                }
                continue;
            }

            if (file_exists($candidatePath) && is_executable($candidatePath)) {
                return realpath($candidatePath);
            }
        }

        throw new RuntimeException('Could not detect composer binary. Please install composer.');
    }

    /**
     * Check if composer binary exists and is executable
     *
     * @param string $composerBin Path to composer binary
     * @return bool True if valid, false otherwise
     */
    public function isValidComposerBin(string $composerBin): bool
    {
        return file_exists($composerBin) && is_executable($composerBin);
    }
}
