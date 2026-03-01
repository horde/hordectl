<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\ConfigManager;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use RuntimeException;

/**
 * Activate Horde installation
 *
 * Copies default configuration files from vendor/horde/horde/config
 * to var/config/horde to initialize the installation.
 *
 * Usage:
 *   hordectl activate           # Copy conf.php.dist to conf.php
 *   hordectl activate --force   # Overwrite existing conf.php
 *
 * @author Ralf Lang <lang@b1-systems.de>
 */
class Activate implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;
    private ConfigManager $configManager;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->configManager = $dependencies->getInstance(ConfigManager::class);
        $this->_parser = $dependencies->getInstance(Parser::class);
        $this->_parser->allowInterspersedArgs = false;
    }

    public function getBaseOptions()
    {
        return [
            new \Horde\Argv\Option(
                '--force',
                [
                    'action' => 'store_true',
                    'help' => 'Overwrite existing conf.php'
                ]
            ),
        ];
    }

    /**
     * Handle the activate command
     *
     * @param array $argv Command arguments
     * @return bool True if handled
     */
    public function handle(array $argv = []): bool
    {
        // Check if this is our command
        if (empty($argv) || $argv[0] !== 'activate') {
            return false;
        }

        list($opts, $args) = $this->handleCommandline($argv);

        try {
            // Get installation directory
            $installDir = $this->configManager->get('HORDE_INSTALL_DIR');
            if (!$installDir) {
                $this->cli->fatal('HORDE_INSTALL_DIR not configured. Run: hordectl config set HORDE_INSTALL_DIR /path/to/horde');
            }

            // Paths
            $sourceDir = $installDir . '/vendor/horde/horde/config';
            $targetDir = $installDir . '/var/config/horde';
            $sourceFile = $sourceDir . '/conf.php.dist';
            $targetFile = $targetDir . '/conf.php';

            // Check if source file exists
            if (!file_exists($sourceFile)) {
                $this->cli->fatal("Source configuration not found: $sourceFile\nIs this a valid Horde installation?");
            }

            // Check if target already exists
            if (file_exists($targetFile) && !($opts->force ?? false)) {
                $this->cli->message("Configuration already exists: $targetFile", 'cli.warning');
                $this->cli->writeln('Use --force to overwrite.');
                return true;
            }

            // Create target directory if needed
            if (!is_dir($targetDir)) {
                if (!mkdir($targetDir, 0755, true)) {
                    $this->cli->fatal("Failed to create directory: $targetDir");
                }
                $this->cli->message("Created directory: $targetDir", 'cli.success');
            }

            // Copy conf.php.dist to conf.php
            if (!copy($sourceFile, $targetFile)) {
                $this->cli->fatal("Failed to copy configuration file");
            }

            // Set permissions
            chmod($targetFile, 0600);

            $this->cli->writeln();
            $this->cli->message('✓ Configuration file created', 'cli.success');
            $this->cli->writeln("  Created: $targetFile");
            $this->cli->writeln("  Source: $sourceFile");

            // Trigger the installer plugin to link configs
            $this->cli->writeln();
            $this->cli->message('Linking configuration to vendor directories...', 'cli.message');

            $composerBin = $this->findComposerBinary($installDir);
            if ($composerBin) {
                chdir($installDir);
                $output = [];
                $return = 0;
                exec("$composerBin horde:reconfigure 2>&1", $output, $return);

                if ($return === 0) {
                    $this->cli->message('✓ Configuration linked successfully', 'cli.success');
                } else {
                    $this->cli->message('Warning: Could not run composer horde:reconfigure', 'cli.warning');
                    $this->cli->writeln('You may need to run it manually:');
                    $this->cli->writeln("  cd $installDir && composer horde:reconfigure");
                }
            } else {
                $this->cli->message('Warning: Composer not found', 'cli.warning');
                $this->cli->writeln('Configuration created but not linked to vendor directories.');
                $this->cli->writeln('Run manually:');
                $this->cli->writeln("  cd $installDir && composer horde:reconfigure");
            }

            $this->cli->writeln();
            $this->cli->message('✓ Horde installation activated!', 'cli.success');
            $this->cli->writeln();
            $this->cli->writeln("  Configuration file created: $targetFile");
            $this->cli->writeln("  Source: $sourceFile");
            $this->cli->writeln();
            $this->cli->writeln('Next steps:');
            $this->cli->writeln('  1. Configure database:');
            $this->cli->writeln('     hordectl configure database');
            $this->cli->writeln();
            $this->cli->writeln('  2. Configure session handler:');
            $this->cli->writeln('     hordectl configure session');
            $this->cli->writeln();
            $this->cli->writeln('  3. Run database migrations:');
            $this->cli->writeln('     ./vendor/bin/horde-db-migrate');
            $this->cli->writeln();

            return true;
        } catch (RuntimeException $e) {
            $this->cli->fatal($e->getMessage());
            return false;
        }
    }

    public function getUsage()
    {
        return 'activate [--force]

Activate a Horde installation by copying default configuration files.

This command copies conf.php.dist from vendor/horde/horde/config to
var/config/horde/conf.php, initializing your Horde installation.

OPTIONS
    --force
        Overwrite existing conf.php if it already exists

EXAMPLES
    # Activate fresh installation
    hordectl activate

    # Overwrite existing configuration
    hordectl activate --force
';
    }

    public function getSummary()
    {
        return 'Activate Horde installation by copying default configuration';
    }

    /**
     * Find composer binary
     *
     * @param string $installDir Installation directory
     * @return string|null Path to composer binary or null if not found
     */
    private function findComposerBinary(string $installDir): ?string
    {
        // Check common locations
        $candidates = [
            $installDir . '/vendor/bin/composer',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
        ];

        // Also check for composer.phar
        if (file_exists($installDir . '/composer.phar')) {
            $candidates[] = 'php ' . $installDir . '/composer.phar';
        }

        foreach ($candidates as $candidate) {
            if (strpos($candidate, 'php ') === 0) {
                // For php composer.phar, just check if the phar exists
                $pharPath = substr($candidate, 4);
                if (file_exists($pharPath)) {
                    return $candidate;
                }
            } elseif (is_executable($candidate)) {
                return $candidate;
            }
        }

        // Try to find in PATH
        $which = trim((string)shell_exec('which composer 2>/dev/null'));
        if ($which && is_executable($which)) {
            return $which;
        }

        return null;
    }
}
