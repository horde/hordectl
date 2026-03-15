<?php

declare(strict_types=1);

namespace Horde\Hordectl;

use Horde\Hordectl\Exception\NoCurrentTargetException;
use Horde\Hordectl\Exception\TargetNotFoundException;
use Exception;

/**
 * Trait for checking target capabilities in commands
 *
 * Provides methods to verify current target supports required operations
 * before executing commands that need specific capabilities.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
trait TargetCapabilityTrait
{
    /**
     * Get the active target (respecting --target flag override)
     *
     * @param ConfigManager|null $config Optional config for testing
     * @return Target Active target
     */
    protected function getActiveTarget(?ConfigManager $config = null): Target
    {
        $config ??= new ConfigManager();
        $resolver = new TargetResolver();

        // Check for --target flag override (stored in dependencies)
        if (isset($this->dependencies)) {
            try {
                $targetOverride = $this->dependencies->getInstance('hordectl.target_override');
                if (!empty($targetOverride)) {
                    return $resolver->getTarget($config, $targetOverride);
                }
            } catch (TargetNotFoundException $e) {
                // Target specified via --target doesn't exist - re-throw
                throw $e;
            } catch (Exception $e) {
                // No override set (getInstance failed), continue to get current target
            }
        }

        // No override, get current target
        return $resolver->getCurrentTarget($config);
    }
    /**
     * Require filesystem commands capability
     *
     * Checks current target supports filesystem operations (local target).
     * Shows helpful error and exits if target doesn't support it.
     *
     * @param ConfigManager|null $config Optional config for testing
     * @return Target Current target with filesystem capability
     */
    protected function requireFilesystemCapability(?ConfigManager $config = null): Target
    {
        try {
            $target = $this->getActiveTarget($config);
        } catch (NoCurrentTargetException $e) {
            $this->cli->fatal(
                "No active target configured.\n"
                . "Run 'hordectl target list' to see available targets or 'hordectl target add' to create one."
            );
        } catch (TargetNotFoundException $e) {
            $this->cli->fatal($e->getMessage());
        }

        if (!$target->supportsFilesystemCommands()) {
            $this->cli->fatal(
                "This command requires a local target with filesystem access.\n"
                . "\n"
                . "Current target: {$target->name} ({$target->type->value})\n"
                . "Location: {$target->getLocationString()}\n"
                . "\n"
                . "Remote targets cannot execute filesystem commands because hordectl\n"
                . "has no direct access to the remote server's filesystem.\n"
                . "\n"
                . "Solution: Switch to a local target:\n"
                . "  hordectl target list     # Show available targets\n"
                . "  hordectl target use <name>  # Switch to local target"
            );
        }

        return $target;
    }

    /**
     * Require API commands capability
     *
     * Checks current target supports API operations (has endpoint configured).
     * Shows helpful error and exits if target doesn't support it.
     *
     * @param ConfigManager|null $config Optional config for testing
     * @return Target Current target with API capability
     */
    protected function requireApiCapability(?ConfigManager $config = null): Target
    {
        try {
            $target = $this->getActiveTarget($config);
        } catch (NoCurrentTargetException $e) {
            $this->cli->fatal(
                "No active target configured.\n"
                . "Run 'hordectl target list' to see available targets or 'hordectl target add' to create one."
            );
        } catch (TargetNotFoundException $e) {
            $this->cli->fatal($e->getMessage());
        }

        if (!$target->supportsApiCommands()) {
            $this->cli->fatal(
                "This command requires an API endpoint.\n"
                . "\n"
                . "Current target: {$target->name} ({$target->type->value})\n"
                . "Location: {$target->getLocationString()}\n"
                . "\n"
                . "This target has no API endpoint configured.\n"
                . "\n"
                . "Solution:\n"
                . "  # Add API endpoint to current target:\n"
                . "  hordectl target update {$target->name} --endpoint=https://example.com/horde --secret=<secret>\n"
                . "\n"
                . "  # Or switch to a target with API access:\n"
                . "  hordectl target list     # Show available targets\n"
                . "  hordectl target use <name>  # Switch to target with API"
            );
        }

        return $target;
    }
}
