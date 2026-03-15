<?php

declare(strict_types=1);

namespace Horde\Hordectl;

/**
 * Manages hordectl configuration
 *
 * Handles reading/writing the config file without requiring Horde bootstrap.
 * Automatically migrates V1 (single-target) config to V2 (multi-target) format.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ConfigManager
{
    private string $configPath;
    private array $config = [];

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? $_SERVER['HOME'] . '/.config/horde/hordectl.php';
        $this->load();
    }

    /**
     * Load configuration from file
     *
     * Automatically migrates V1 to V2 format if needed.
     */
    public function load(): void
    {
        if (file_exists($this->configPath)) {
            $this->config = require $this->configPath;

            // Auto-migrate V1 to V2 if needed
            if (!$this->isV2Format()) {
                $this->migrateToV2();
            }
        }
    }

    /**
     * Get configuration value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set configuration value
     */
    public function set(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * Check if configuration key exists
     */
    public function has(string $key): bool
    {
        return isset($this->config[$key]);
    }

    /**
     * Get all configuration
     */
    public function getAll(): array
    {
        return $this->config;
    }

    /**
     * Save configuration to file
     */
    public function save(): bool
    {
        $dir = dirname($this->configPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o755, true)) {
                return false;
            }
        }

        $export = var_export($this->config, true);
        $content = <<<PHP
            <?php
            /**
             * Hordectl Configuration (Multi-Target Format)
             *
             * This file stores multiple Horde installation targets.
             * You can manually edit this file if needed.
             *
             * Configuration structure:
             *
             * - current-target: Name of the active target
             * - targets: Array of target configurations
             *   - name => [
             *       type: 'local' or 'remote'
             *       horde_base: Path to Horde base (vendor/horde/horde) - local only
             *       horde_install_dir: Path to Horde installation root - local only
             *       endpoint: Base URL for REST API (e.g., http://localhost/horde) - optional for local, required for remote
             *       admin_secret: Bearer token for API authentication - leave empty to read from conf.php
             *       verify_ssl: Whether to verify SSL certificates (default: true)
             *       description: Optional human-readable description
             *     ]
             *
             * Never commit this file with admin_secret to version control.
             */

            return {$export};

            PHP;

        return file_put_contents($this->configPath, $content) !== false;
    }

    /**
     * Get config file path
     */
    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    /**
     * Check if configuration is in V2 (multi-target) format
     *
     * @return bool True if V2 format
     */
    public function isV2Format(): bool
    {
        return isset($this->config['targets']);
    }

    /**
     * Migrate V1 (single-target) config to V2 (multi-target) format
     *
     * Creates backup of old config and converts to new structure.
     *
     * @return bool True if migration succeeded
     */
    public function migrateToV2(): bool
    {
        if ($this->isV2Format()) {
            return false; // Already V2
        }

        // Backup old config
        if (file_exists($this->configPath)) {
            copy($this->configPath, $this->configPath . '.bak');
        }

        $oldConfig = $this->config;

        // Determine target type from old config
        $hasPath = isset($oldConfig['HORDE_BASE']) || isset($oldConfig['HORDE_INSTALL_DIR']);
        $hasApi = isset($oldConfig['admin_api']['endpoint']) && !empty($oldConfig['admin_api']['endpoint']);

        $type = 'local'; // Default to local
        if (!$hasPath && $hasApi) {
            $type = 'remote';
        }

        // Create default target
        $defaultTarget = [
            'type' => $type,
        ];

        if ($hasPath) {
            if (isset($oldConfig['HORDE_BASE'])) {
                $defaultTarget['horde_base'] = $oldConfig['HORDE_BASE'];
            }
            if (isset($oldConfig['HORDE_INSTALL_DIR'])) {
                $defaultTarget['horde_install_dir'] = $oldConfig['HORDE_INSTALL_DIR'];
            }
        }

        if ($hasApi) {
            $defaultTarget['endpoint'] = $oldConfig['admin_api']['endpoint'];
            if (isset($oldConfig['admin_api']['admin_secret'])) {
                $defaultTarget['admin_secret'] = $oldConfig['admin_api']['admin_secret'];
            }
        }

        // Create new V2 config
        $newConfig = [
            'current-target' => 'default',
            'targets' => [
                'default' => $defaultTarget,
            ],
        ];

        $this->config = $newConfig;
        return $this->save();
    }
}
