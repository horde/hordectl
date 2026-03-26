<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl;

use Horde\PhpConfigFile\PhpConfigFile;
use RuntimeException;
use InvalidArgumentException;

/**
 * Helper class for reading and writing Horde configuration files
 *
 * Provides a CLI-friendly interface for manipulating Horde config files
 * while preserving protected sections (CONFIG START/END markers) and
 * manual configuration (pre-header and post-footer content).
 *
 * Features:
 * - Dot-notation path access (e.g., 'sql.phptype')
 * - Preserves protected sections
 * - Creates automatic backups
 * - Works without Horde bootstrap (perfect for minimal mode)
 *
 * @author Ralf Lang <ralf.lang@ralf-lang.de>
 */
class ConfigHelper
{
    private PhpConfigFile $file;
    private array $conf = [];
    private string $confDir;
    private string $confFile;
    private bool $fileExists = false;

    /**
     * Create a new ConfigHelper instance
     *
     * @param string $app Application name (typically 'horde')
     * @param string|null $installDir Override installation directory (for testing)
     * @throws RuntimeException If config directory cannot be determined
     */
    public function __construct(string $app = 'horde', ?string $installDir = null)
    {
        // Determine config directory - bundle uses var/config/$app structure
        if ($installDir !== null) {
            $this->confDir = $installDir . '/var/config/' . $app;
        } elseif (($envDir = getenv('HORDE_INSTALL_DIR')) !== false && $envDir !== '') {
            $this->confDir = $envDir . '/var/config/' . $app;
        } else {
            throw new RuntimeException(
                'Cannot determine Horde installation directory. '
                . 'Set HORDE_INSTALL_DIR environment variable or use ConfigManager.'
            );
        }

        // Config file path
        $this->confFile = $this->confDir . '/conf.php';
        $this->fileExists = file_exists($this->confFile);

        // Check if configuration directory exists
        if (!is_dir($this->confDir)) {
            throw new RuntimeException(
                "Configuration directory does not exist: {$this->confDir}\n\n"
                . "The Horde installation may not be activated.\n"
                . "Run 'hordectl activate' to initialize the installation."
            );
        }

        // Warn if conf.php doesn't exist
        if (!$this->fileExists) {
            throw new RuntimeException(
                "Configuration file not found: {$this->confFile}\n\n"
                . "The Horde installation is not activated.\n"
                . "Run 'hordectl activate' to copy the default configuration."
            );
        }

        // Create PhpConfigFile instance with Horde markers
        $this->file = new PhpConfigFile(
            configFilePath: $this->confFile,
            header: '/* CONFIG START. DO NOT CHANGE ANYTHING IN OR AFTER THIS LINE. */',
            footer: '/* CONFIG END. DO NOT CHANGE ANYTHING IN OR BEFORE THIS LINE. */'
        );

        // Load current configuration
        $this->loadCurrentConfig();
    }

    /**
     * Load current configuration from file
     *
     * @throws RuntimeException If file cannot be read or parsed
     */
    private function loadCurrentConfig(): void
    {
        $this->file->readConfigFile();

        // Parse only the managed section (between markers)
        $parsed = $this->file->parseContent(area: 'contentBetweenHeaderAndFooter');

        // Extract $conf array from parsed variables
        $this->conf = $parsed['conf'] ?? [];
    }

    /**
     * Get configuration value using dot notation
     *
     * Examples:
     *   getValue('sql.phptype')         -> $conf['sql']['phptype']
     *   getValue('sql.hostspec')        -> $conf['sql']['hostspec']
     *   getValue('auth.driver')         -> $conf['auth']['driver']
     *
     * @param string $path Dot-separated path to configuration value
     * @return mixed Configuration value or null if not found
     */
    public function getValue(string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $this->conf;

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Set configuration value using dot notation
     *
     * Examples:
     *   setValue('sql.phptype', 'mysql')
     *   setValue('sql.hostspec', 'localhost')
     *   setValue('auth.driver', 'sql')
     *
     * Creates intermediate arrays as needed.
     *
     * @param string $path Dot-separated path to configuration value
     * @param mixed $value Value to set
     * @throws InvalidArgumentException If path is empty
     */
    public function setValue(string $path, mixed $value): void
    {
        if (empty($path)) {
            throw new InvalidArgumentException('Configuration path cannot be empty');
        }

        $keys = explode('.', $path);
        $ref = &$this->conf;

        // Navigate to the target location, creating arrays as needed
        foreach ($keys as $i => $key) {
            // Last key: set the value
            if ($i === count($keys) - 1) {
                $ref[$key] = $value;
                break;
            }

            // Intermediate keys: ensure array exists
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }

            $ref = &$ref[$key];
        }
    }

    /**
     * Unset configuration value using dot notation
     *
     * Examples:
     *   unsetValue('sql.port')  - Remove $conf['sql']['port']
     *
     * @param string $path Dot-separated path to configuration value
     * @return bool True if value was unset, false if it didn't exist
     */
    public function unsetValue(string $path): bool
    {
        $keys = explode('.', $path);
        $ref = &$this->conf;

        // Navigate to parent of target
        for ($i = 0; $i < count($keys) - 1; $i++) {
            $key = $keys[$i];
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                return false; // Path doesn't exist
            }
            $ref = &$ref[$key];
        }

        // Unset the final key
        $finalKey = $keys[count($keys) - 1];
        if (isset($ref[$finalKey])) {
            unset($ref[$finalKey]);
            return true;
        }

        return false;
    }

    /**
     * Check if configuration value exists
     *
     * @param string $path Dot-separated path to configuration value
     * @return bool True if value exists (even if null)
     */
    public function hasValue(string $path): bool
    {
        $keys = explode('.', $path);
        $value = $this->conf;

        foreach ($keys as $i => $key) {
            if (!is_array($value)) {
                return false;
            }

            // For last key, check with array_key_exists (to distinguish null from missing)
            if ($i === count($keys) - 1) {
                return array_key_exists($key, $value);
            }

            if (!isset($value[$key])) {
                return false;
            }

            $value = $value[$key];
        }

        return true;
    }

    /**
     * Get all configuration as array
     *
     * @return array Complete configuration array
     */
    public function getAll(): array
    {
        return $this->conf;
    }

    /**
     * Set entire configuration array
     *
     * @param array $conf Configuration array
     */
    public function setAll(array $conf): void
    {
        $this->conf = $conf;
    }

    /**
     * Get configuration file path
     *
     * @return string Path to conf.php
     */
    public function getConfigFile(): string
    {
        return $this->confFile;
    }

    /**
     * Get configuration directory path
     *
     * @return string Path to config directory
     */
    public function getConfigDir(): string
    {
        return $this->confDir;
    }

    /**
     * Check if configuration file exists
     *
     * @return bool True if conf.php exists
     */
    public function fileExists(): bool
    {
        return $this->fileExists;
    }

    /**
     * Save configuration back to file
     *
     * Preserves:
     * - Content before CONFIG START marker (pre-header)
     * - Content after CONFIG END marker (post-footer)
     *
     * Creates backup:
     * - Saves current file to conf.bak.php before writing
     *
     * @return bool True on success
     * @throws RuntimeException If backup or write fails
     */
    public function save(): bool
    {
        // Ensure config directory exists
        if (!is_dir($this->confDir)) {
            throw new RuntimeException("Config directory does not exist: {$this->confDir}");
        }

        if (!is_writable($this->confDir)) {
            throw new RuntimeException("Config directory is not writable: {$this->confDir}");
        }

        // Create backup if file exists
        if ($this->fileExists) {
            $backupFile = $this->confDir . '/conf.bak.php';
            if (!copy($this->confFile, $backupFile)) {
                throw new RuntimeException("Failed to create backup: {$backupFile}");
            }
        }

        // Write configuration
        // PhpConfigFile expects variables as associative array
        $this->file->writeConfigFile(['conf' => $this->conf]);

        // Update file existence flag
        $this->fileExists = true;

        return true;
    }

    /**
     * Get path to backup file
     *
     * @return string Path to conf.bak.php
     */
    public function getBackupFile(): string
    {
        return $this->confDir . '/conf.bak.php';
    }

    /**
     * Check if backup file exists
     *
     * @return bool True if conf.bak.php exists
     */
    public function backupExists(): bool
    {
        return file_exists($this->getBackupFile());
    }

    /**
     * Restore configuration from backup
     *
     * @return bool True on success
     * @throws RuntimeException If backup doesn't exist or restore fails
     */
    public function restoreFromBackup(): bool
    {
        $backupFile = $this->getBackupFile();

        if (!file_exists($backupFile)) {
            throw new RuntimeException("Backup file does not exist: {$backupFile}");
        }

        if (!copy($backupFile, $this->confFile)) {
            throw new RuntimeException("Failed to restore from backup");
        }

        // Reload configuration
        $this->loadCurrentConfig();

        return true;
    }

    /**
     * Get configuration as formatted string for display
     *
     * Masks sensitive values (passwords, secrets, etc.)
     *
     * @param bool $maskSensitive Mask sensitive values (default: true)
     * @return string Formatted configuration
     */
    public function formatForDisplay(bool $maskSensitive = true): string
    {
        return $this->formatArray($this->conf, 0, $maskSensitive);
    }

    /**
     * Recursively format array for display
     *
     * @param array $array Array to format
     * @param int $indent Indentation level
     * @param bool $maskSensitive Mask sensitive values
     * @return string Formatted string
     */
    private function formatArray(array $array, int $indent = 0, bool $maskSensitive = true): string
    {
        $output = '';
        $indentStr = str_repeat('  ', $indent);

        foreach ($array as $key => $value) {
            // Check if key is sensitive
            $isSensitive = $maskSensitive && $this->isSensitiveKey($key);

            if (is_array($value)) {
                $output .= $indentStr . $key . ":\n";
                $output .= $this->formatArray($value, $indent + 1, $maskSensitive);
            } else {
                $displayValue = $isSensitive ? '********' : $this->formatValue($value);
                $output .= $indentStr . $key . ': ' . $displayValue . "\n";
            }
        }

        return $output;
    }

    /**
     * Check if key is sensitive (password, secret, etc.)
     *
     * @param string $key Configuration key
     * @return bool True if sensitive
     */
    private function isSensitiveKey(string $key): bool
    {
        $sensitiveKeys = [
            'password',
            'passwd',
            'pass',
            'secret',
            'token',
            'key',
            'api_key',
            'apikey',
            'auth_key',
            'private_key',
            'encryption_key',
        ];

        $lowerKey = strtolower($key);

        foreach ($sensitiveKeys as $sensitive) {
            if (str_contains($lowerKey, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format value for display
     *
     * @param mixed $value Value to format
     * @return string Formatted value
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '(null)';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return $value === '' ? '(empty)' : $value;
        }

        return (string) $value;
    }
}
