<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\Configure;

/**
 * Common helper methods for configure subcommands
 *
 * Provides reusable functionality for boolean parsing, formatting,
 * and user prompts.
 *
 * @author Ralf Lang <lang@b1-systems.de>
 */
trait ConfigureHelperTrait
{
    /**
     * Prompt for boolean value
     *
     * @param string $prompt Prompt text
     * @param bool $default Default value
     * @return bool User response
     */
    private function promptBoolean(string $prompt, bool $default): bool
    {
        $defaultStr = $default ? 'Y/n' : 'y/N';
        $response = $this->cli->prompt("{$prompt} [{$defaultStr}]:", $default ? 'y' : 'n');
        return strtolower($response) === 'y';
    }

    /**
     * Parse boolean from string
     *
     * Accepts multiple formats:
     * - true/false
     * - yes/no
     * - y/n
     * - 1/0
     * - on/off
     *
     * @param string $value String value
     * @return bool Boolean value
     */
    private function parseBoolean(string $value): bool
    {
        $lower = strtolower($value);
        return in_array($lower, ['true', '1', 'yes', 'y', 'on']);
    }

    /**
     * Format boolean for display
     *
     * @param bool $value Boolean value
     * @param string $trueText Text for true (default: 'yes')
     * @param string $falseText Text for false (default: 'no')
     * @return string Formatted string
     */
    private function formatBoolean(bool $value, string $trueText = 'yes', string $falseText = 'no'): string
    {
        return $value ? $trueText : $falseText;
    }
}
