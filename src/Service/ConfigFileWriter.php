<?php

declare(strict_types=1);

namespace Horde\Hordectl\Service;

use Horde\PhpConfigFile\PhpConfigFile;
use RuntimeException;
use Exception;

/**
 * Config file writer service
 *
 * Handles reading and writing Horde conf.php configuration files using PhpConfigFile library.
 * Specifically for managing admin_secret for hordectl REST API.
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Hordectl
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ConfigFileWriter
{
    /**
     * Generate cryptographically random admin_secret
     *
     * @return string 128-character base64-encoded secret
     */
    public function generateSecret(): string
    {
        $bytes = random_bytes(96); // 96 bytes = 128 base64 chars
        return base64_encode($bytes);
    }

    /**
     * Read current admin_secret from conf.php
     *
     * @param string $confPath Path to conf.php
     * @return string|null Current secret or null if not set
     * @throws RuntimeException if conf.php doesn't exist or can't be read
     */
    public function readAdminSecret(string $confPath): ?string
    {
        if (!file_exists($confPath)) {
            throw new RuntimeException("Config file not found: {$confPath}");
        }

        try {
            $config = new PhpConfigFile($confPath);
            $config->readConfigFile();
            $parsed = $config->parseContent();

            return $parsed['conf']['admin_api']['admin_secret'] ?? null;
        } catch (Exception $e) {
            // Config might not have admin_api section yet
            return null;
        }
    }

    /**
     * Write admin_secret to conf.php
     *
     * @param string $confPath Path to conf.php
     * @param string $secret The admin_secret to write
     * @return bool True on success
     * @throws RuntimeException on failure
     */
    public function writeAdminSecret(string $confPath, string $secret): bool
    {
        if (!file_exists($confPath)) {
            throw new RuntimeException("Config file not found: {$confPath}");
        }

        // Create backup
        $backupPath = $confPath . '.backup.' . date('Y-m-d_H-i-s');
        if (!copy($confPath, $backupPath)) {
            throw new RuntimeException("Failed to create backup at: {$backupPath}");
        }

        try {
            $config = new PhpConfigFile($confPath);
            $config->readConfigFile();
            $parsed = $config->parseContent();

            // Update the config array - must be inside $conf
            $parsed['conf']['admin_api']['admin_secret'] = $secret;
            $parsed['conf']['admin_api']['enabled'] = true;
            $parsed['conf']['admin_api']['path'] = 'admin/api';

            // Write back
            $config->writeConfigFile($parsed);

            // Verify write succeeded
            $verifySecret = $this->readAdminSecret($confPath);
            if ($verifySecret !== $secret) {
                throw new RuntimeException("Verification failed - secret not written correctly");
            }

            return true;
        } catch (Exception $e) {
            throw new RuntimeException("Failed to write secret: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Enable admin API in conf.php
     *
     * @param string $confPath Path to conf.php
     * @return bool True on success
     * @throws RuntimeException on failure
     */
    public function enableAdminApi(string $confPath): bool
    {
        if (!file_exists($confPath)) {
            throw new RuntimeException("Config file not found: {$confPath}");
        }

        try {
            $config = new PhpConfigFile($confPath);
            $config->readConfigFile();
            $parsed = $config->parseContent();

            $parsed['conf']['admin_api']['enabled'] = true;

            $config->writeConfigFile($parsed);
            return true;
        } catch (Exception $e) {
            throw new RuntimeException("Failed to enable admin API: " . $e->getMessage(), 0, $e);
        }
    }
}
