<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\Configure;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\ConfigHelper;
use Horde\Hordectl\ConfigManager;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Horde\Argv\Option;
use RuntimeException;

/**
 * Configure permissions system
 *
 * Provides interactive and command-line modes for configuring Horde
 * permissions backend. Supports SQL backend with caching options.
 *
 * Usage:
 *   hordectl configure permissions                    # Interactive mode
 *   hordectl configure perms --show                   # Show current config
 *   hordectl configure perms --driver sql ...         # CLI mode
 *
 * @author Ralf Lang <lang@b1-systems.de>
 */
class Permissions implements Module, ModuleUsage
{
    use ModuleTrait;
    use ConfigureHelperTrait;

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

    public function getPositionalArgs(): array
    {
        return ['permissions', 'perms'];
    }

    public function getBaseOptions()
    {
        return [
            new Option(
                '--driver',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Permissions driver (sql, mock)'
                ]
            ),
            new Option(
                '--cache',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Enable caching (true/false)'
                ]
            ),
            new Option(
                '--cache-lifetime',
                [
                    'action' => 'store',
                    'type' => 'int',
                    'help' => 'Cache lifetime in seconds'
                ]
            ),
            new Option(
                '--interactive',
                [
                    'action' => 'store_true',
                    'help' => 'Interactive mode with prompts'
                ]
            ),
            new Option(
                '--show',
                [
                    'action' => 'store_true',
                    'help' => 'Show current permissions configuration'
                ]
            ),
        ];
    }

    /**
     * Handle the permissions configuration command
     *
     * @param array $argv Command arguments
     * @return bool True if handled
     */
    public function handle(array $argv = []): bool
    {
        // Check if this is our command
        if (empty($argv) || ($argv[0] !== 'permissions' && $argv[0] !== 'perms')) {
            return false;
        }

        list($opts, $args) = $this->handleCommandline($argv);

        try {
            // Get installation directory
            $installDir = $this->configManager->get('HORDE_INSTALL_DIR');
            if (!$installDir) {
                $this->cli->fatal('HORDE_INSTALL_DIR not configured. Run: hordectl config set HORDE_INSTALL_DIR /path/to/horde');
            }

            // Create ConfigHelper
            $helper = new ConfigHelper('horde', $installDir);

            // Show current configuration
            if ($opts->show ?? false) {
                $this->showCurrentConfig($helper);
                return true;
            }

            // Determine mode: interactive or CLI arguments
            if (($opts->interactive ?? false) || !$this->hasConfigOptions($opts)) {
                $this->interactiveMode($helper);
            } else {
                $this->cliMode($helper, $opts);
            }

            // Save configuration
            $this->cli->writeln();
            $helper->save();
            $this->cli->message('✓ Permissions configuration saved', 'cli.success');
            $this->cli->message('  Backup created: ' . basename($helper->getBackupFile()), 'cli.message');
            $this->cli->writeln();

            return true;
        } catch (RuntimeException $e) {
            $this->cli->fatal($e->getMessage());
            return false;
        }
    }

    /**
     * Check if any configuration options were provided
     *
     * @param object $opts Parsed options
     * @return bool True if configuration options present
     */
    private function hasConfigOptions(object $opts): bool
    {
        return isset($opts->driver) || isset($opts->cache) || isset($opts->cache_lifetime);
    }

    /**
     * Interactive mode with prompts
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function interactiveMode(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Permissions System Configuration (Interactive Mode)');
        $this->cli->writeln('===================================================');
        $this->cli->writeln();

        $this->cli->writeln('Available permissions drivers:');
        $this->cli->writeln('  sql  - SQL database backend (uses main database)');
        $this->cli->writeln('  mock - Mock driver for testing');
        $this->cli->writeln();

        $currentDriver = $helper->getValue('perms.driver') ?? 'sql';
        $driver = $this->cli->prompt(
            'Permissions driver:',
            $currentDriver
        );
        $helper->setValue('perms.driver', $driver);

        $this->cli->writeln();
        $this->cli->writeln('Caching settings:');
        $this->cli->writeln('(Caching improves performance for frequently checked permissions)');
        $this->cli->writeln();

        $currentCache = $helper->getValue('perms.cache') ?? true;
        $cache = $this->promptBoolean(
            'Enable caching?',
            $currentCache
        );
        $helper->setValue('perms.cache', $cache);

        if ($cache) {
            $currentLifetime = $helper->getValue('perms.cache_lifetime') ?? 300;
            $lifetime = $this->cli->prompt(
                'Cache lifetime in seconds:',
                (string)$currentLifetime
            );
            $helper->setValue('perms.cache_lifetime', (int)$lifetime);
        }

        if ($driver === 'sql') {
            $this->cli->writeln();
            $this->cli->message('Note: SQL driver uses the main database configuration.', 'cli.message');
            $this->cli->writeln('      Run "hordectl configure database" to configure the database.');
            $this->cli->writeln();
        }
    }

    /**
     * CLI mode with command-line arguments
     *
     * @param ConfigHelper $helper Configuration helper
     * @param object $opts Parsed options
     */
    private function cliMode(ConfigHelper $helper, object $opts): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Updating permissions configuration...');
        $this->cli->writeln();

        if (isset($opts->driver)) {
            $helper->setValue('perms.driver', $opts->driver);
            $this->cli->writeln("  Permissions driver: {$opts->driver}");
        }

        if (isset($opts->cache)) {
            $value = $this->parseBoolean($opts->cache);
            $helper->setValue('perms.cache', $value);
            $this->cli->writeln("  Caching: " . ($value ? 'enabled' : 'disabled'));
        }

        if (isset($opts->cache_lifetime)) {
            $helper->setValue('perms.cache_lifetime', $opts->cache_lifetime);
            $this->cli->writeln("  Cache lifetime: {$opts->cache_lifetime} seconds");
        }
    }

    /**
     * Show current permissions configuration
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function showCurrentConfig(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Current Permissions Configuration');
        $this->cli->writeln('==================================');
        $this->cli->writeln();

        $perms = $helper->getValue('perms');

        if (empty($perms)) {
            $this->cli->message('No permissions configuration found.', 'cli.warning');
            $this->cli->writeln('Using defaults: SQL driver with caching enabled.');
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('Backend:');
        $this->cli->writeln('  Driver:         ' . ($perms['driver'] ?? 'sql'));

        $this->cli->writeln();
        $this->cli->writeln('Caching:');
        $this->cli->writeln('  Enabled:        ' . $this->formatBoolean($perms['cache'] ?? true));
        $this->cli->writeln('  Lifetime:       ' . ($perms['cache_lifetime'] ?? 300) . ' seconds');

        $this->cli->writeln();
        $this->cli->writeln('Notes:');
        $driver = $perms['driver'] ?? 'sql';
        if ($driver === 'sql') {
            $this->cli->writeln('  SQL driver uses the main database configuration.');
            $this->cli->writeln('  Run "hordectl configure database --show" to view database settings.');
        }

        $this->cli->writeln();
    }
}
