<?php
namespace Horde\Hordectl;

/**
 * Manages hordectl configuration
 *
 * Handles reading/writing the config file without requiring Horde bootstrap
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
     */
    public function load(): void
    {
        if (file_exists($this->configPath)) {
            $this->config = require $this->configPath;
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
            if (!mkdir($dir, 0755, true)) {
                return false;
            }
        }

        $export = var_export($this->config, true);
        $content = <<<PHP
<?php
/**
 * Hordectl Configuration
 *
 * This file stores paths to your Horde installation.
 * You can manually edit this file if needed.
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
}
