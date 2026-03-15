<?php

declare(strict_types=1);

namespace Horde\Hordectl;

use Horde\Hordectl\Exception\NoCurrentTargetException;
use Horde\Hordectl\Exception\TargetNotFoundException;

/**
 * Target resolver
 *
 * Resolves target names to Target objects from configuration.
 * Handles CRUD operations for targets in configuration.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class TargetResolver
{
    /**
     * Get current target from configuration
     *
     * @param ConfigManager $config Configuration manager
     * @return Target Current target
     * @throws NoCurrentTargetException If no current target is set
     * @throws TargetNotFoundException If current target name doesn't exist
     */
    public function getCurrentTarget(ConfigManager $config): Target
    {
        $currentName = $config->get('current-target');

        if ($currentName === null) {
            throw new NoCurrentTargetException(
                "No current target set. Use 'hordectl target use <name>' to select a target."
            );
        }

        return $this->getTarget($config, $currentName);
    }

    /**
     * Get target by name from configuration
     *
     * @param ConfigManager $config Configuration manager
     * @param string $name Target name
     * @return Target Target object
     * @throws TargetNotFoundException If target doesn't exist
     */
    public function getTarget(ConfigManager $config, string $name): Target
    {
        $targets = $config->get('targets', []);

        if (!isset($targets[$name])) {
            throw new TargetNotFoundException(
                "Target '{$name}' not found. Use 'hordectl target list' to see available targets."
            );
        }

        return Target::fromArray($name, $targets[$name]);
    }

    /**
     * Get all targets from configuration
     *
     * @param ConfigManager $config Configuration manager
     * @return array<string, Target> Array of targets keyed by name
     */
    public function getAllTargets(ConfigManager $config): array
    {
        $targets = $config->get('targets', []);
        $result = [];

        foreach ($targets as $name => $targetConfig) {
            $result[$name] = Target::fromArray($name, $targetConfig);
        }

        return $result;
    }

    /**
     * Check if target exists in configuration
     *
     * @param ConfigManager $config Configuration manager
     * @param string $name Target name
     * @return bool True if target exists
     */
    public function targetExists(ConfigManager $config, string $name): bool
    {
        $targets = $config->get('targets', []);
        return isset($targets[$name]);
    }

    /**
     * Save target to configuration
     *
     * Creates or updates a target in configuration.
     *
     * @param ConfigManager $config Configuration manager
     * @param Target $target Target to save
     * @return void
     */
    public function saveTarget(ConfigManager $config, Target $target): void
    {
        $targets = $config->get('targets', []);
        $targets[$target->name] = $target->toArray();
        $config->set('targets', $targets);
        $config->save();
    }

    /**
     * Delete target from configuration
     *
     * If deleted target was current, unsets current-target.
     *
     * @param ConfigManager $config Configuration manager
     * @param string $name Target name
     * @return void
     */
    public function deleteTarget(ConfigManager $config, string $name): void
    {
        $targets = $config->get('targets', []);
        unset($targets[$name]);
        $config->set('targets', $targets);

        // If deleted target was current, unset current-target
        if ($config->get('current-target') === $name) {
            $config->set('current-target', null);
        }

        $config->save();
    }

    /**
     * Set current target in configuration
     *
     * @param ConfigManager $config Configuration manager
     * @param string $name Target name
     * @return void
     * @throws TargetNotFoundException If target doesn't exist
     */
    public function setCurrentTarget(ConfigManager $config, string $name): void
    {
        if (!$this->targetExists($config, $name)) {
            throw new TargetNotFoundException("Target '{$name}' does not exist.");
        }

        $config->set('current-target', $name);
        $config->save();
    }

    /**
     * Rename target in configuration
     *
     * If renamed target is current, updates current-target reference.
     *
     * @param ConfigManager $config Configuration manager
     * @param string $oldName Current target name
     * @param string $newName New target name
     * @return void
     * @throws TargetNotFoundException If old target doesn't exist
     */
    public function renameTarget(ConfigManager $config, string $oldName, string $newName): void
    {
        // Get existing target
        $target = $this->getTarget($config, $oldName);

        // Delete old target
        $targets = $config->get('targets', []);
        unset($targets[$oldName]);

        // Create new target with same config but new name
        $newTarget = new Target(
            name: $newName,
            type: $target->type,
            hordeBase: $target->hordeBase,
            hordeInstallDir: $target->hordeInstallDir,
            endpoint: $target->endpoint,
            adminSecret: $target->adminSecret,
            verifySsl: $target->verifySsl,
            description: $target->description,
            autoDetected: $target->autoDetected,
            fromEnv: $target->fromEnv,
        );

        $targets[$newName] = $newTarget->toArray();
        $config->set('targets', $targets);

        // Update current-target if needed
        if ($config->get('current-target') === $oldName) {
            $config->set('current-target', $newName);
        }

        $config->save();
    }
}
