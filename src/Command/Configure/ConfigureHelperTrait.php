<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\Configure;

use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Exception\NoCurrentTargetException;
use Horde\Hordectl\Exception\TargetNotFoundException;
use Horde\Hordectl\Target;
use Horde\Hordectl\TargetResolver;
use Exception;

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
     * Require filesystem commands capability for configure operations
     *
     * Configure commands need local filesystem access to modify conf.php.
     *
     * @param ConfigManager|null $config Optional config for testing
     * @return Target Current target with filesystem capability
     */
    protected function requireConfigureCapability(?ConfigManager $config = null): Target
    {
        $config ??= new ConfigManager();
        $resolver = new TargetResolver();

        // Check for --target flag override (stored in dependencies)
        $target = null;
        if (isset($this->dependencies)) {
            try {
                $targetOverride = $this->dependencies->getInstance('hordectl.target_override');
                if (!empty($targetOverride)) {
                    $target = $resolver->getTarget($config, $targetOverride);
                }
            } catch (Exception $e) {
                // No override set, continue to get current target
            }
        }

        // No override, get current target
        if ($target === null) {
            try {
                $target = $resolver->getCurrentTarget($config);
            } catch (NoCurrentTargetException $e) {
                $this->cli->fatal(
                    "No active target configured.\n"
                    . "Run 'hordectl target list' to see available targets or 'hordectl target add' to create one."
                );
            } catch (TargetNotFoundException $e) {
                $this->cli->fatal($e->getMessage());
            }
        }

        if (!$target->supportsFilesystemCommands()) {
            $this->cli->fatal(
                "Configure commands require a local target with filesystem access.\n"
                . "\n"
                . "Current target: {$target->name} ({$target->type->value})\n"
                . "Location: {$target->getLocationString()}\n"
                . "\n"
                . "Remote targets cannot execute configure commands because hordectl\n"
                . "has no direct access to the remote server's filesystem to modify conf.php.\n"
                . "\n"
                . "Solution: Switch to a local target:\n"
                . "  hordectl target list     # Show available targets\n"
                . "  hordectl target use <name>  # Switch to local target"
            );
        }

        return $target;
    }
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
