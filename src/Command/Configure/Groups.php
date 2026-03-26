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
use Horde\Hordectl\Output;
use Horde\Hordectl\ConfigHelper;
use Horde\Hordectl\ConfigManager;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Horde\Argv\Option;
use RuntimeException;
use Horde\Cli\Cli as HordeCli;

/**
 * Configure groups backend and settings
 *
 * Provides interactive and command-line modes for configuring Horde groups
 * backend. Supports SQL and LDAP backends with caching options.
 *
 * Usage:
 *   hordectl configure groups                    # Interactive mode
 *   hordectl configure groups --show             # Show current config
 *   hordectl configure groups --driver sql ...   # CLI mode
 *
 * @author Ralf Lang <ralf.lang@ralf-lang.de>
 */
class Groups implements Module, ModuleUsage
{
    use ModuleTrait;
    use ConfigureHelperTrait;

    protected HordeCli $cli;
    protected Output $output;
    private ConfigManager $configManager;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->configManager = $dependencies->getInstance(ConfigManager::class);
        $this->_parser = $dependencies->getInstance(Parser::class);
        $this->_parser->allowInterspersedArgs = false;
    }

    public function getBaseOptions()
    {
        return [
            new Option(
                '--driver',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Groups driver (sql, ldap, contactlists, mock)',
                ]
            ),
            new Option(
                '--cache',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Enable caching (true/false)',
                ]
            ),
            new Option(
                '--cache-lifetime',
                [
                    'action' => 'store',
                    'type' => 'int',
                    'help' => 'Cache lifetime in seconds',
                ]
            ),
            new Option(
                '--interactive',
                [
                    'action' => 'store_true',
                    'help' => 'Interactive mode with prompts',
                ]
            ),
            new Option(
                '--show',
                [
                    'action' => 'store_true',
                    'help' => 'Show current groups configuration',
                ]
            ),
        ];
    }

    /**
     * Handle the groups configuration command
     *
     * @param array $argv Command arguments
     * @return bool True if handled
     */
    public function handle(array $argv = []): bool
    {
        // Check if this is our command
        if (empty($argv) || $argv[0] !== 'groups') {
            return false;
        }

        // Check target capability
        $target = $this->requireConfigureCapability();

        [$opts, $args] = $this->handleCommandline($argv);

        try {
            // Get installation directory from target
            $installDir = $target->hordeInstallDir;
            if (!$installDir) {
                $this->cli->fatal("Target '{$target->name}' has no installation directory configured.");
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
            $this->output->ok('Groups configuration saved');
            $this->output->info('  Backup created: ' . basename($helper->getBackupFile()));
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
        $this->cli->writeln('Groups Backend Configuration (Interactive Mode)');
        $this->cli->writeln('===============================================');
        $this->cli->writeln();

        $this->cli->writeln('Available group drivers:');
        $this->cli->writeln('  sql          - SQL database backend (uses main database)');
        $this->cli->writeln('  ldap         - LDAP backend (uses LDAP configuration)');
        $this->cli->writeln('  contactlists - Contact lists as groups');
        $this->cli->writeln('  mock         - Mock driver for testing');
        $this->cli->writeln();

        $currentDriver = $helper->getValue('group.driver') ?? 'sql';
        $driver = $this->cli->prompt(
            'Groups driver:',
            $currentDriver
        );
        $helper->setValue('group.driver', $driver);

        $this->cli->writeln();
        $this->cli->writeln('Caching settings:');
        $this->cli->writeln('(Caching improves performance for frequently accessed groups)');
        $this->cli->writeln();

        $currentCache = $helper->getValue('group.cache') ?? true;
        $cache = $this->promptBoolean(
            'Enable caching?',
            $currentCache
        );
        $helper->setValue('group.cache', $cache);

        if ($cache) {
            $currentLifetime = $helper->getValue('group.cache_lifetime') ?? 300;
            $lifetime = $this->cli->prompt(
                'Cache lifetime in seconds:',
                (string) $currentLifetime
            );
            $helper->setValue('group.cache_lifetime', (int) $lifetime);
        }

        // Driver-specific configuration
        if ($driver === 'sql') {
            $this->cli->writeln();
            $this->output->info('Note: SQL driver uses the main database configuration.');
            $this->cli->writeln('      Run "hordectl configure database" to configure the database.');
            $this->cli->writeln();
        } elseif ($driver === 'ldap') {
            $this->cli->writeln();
            $this->output->info('Note: LDAP driver uses the main LDAP configuration.');
            $this->cli->writeln('      Run "hordectl configure ldap" to configure LDAP.');
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
        $this->cli->writeln('Updating groups configuration...');
        $this->cli->writeln();

        if (isset($opts->driver)) {
            $helper->setValue('group.driver', $opts->driver);
            $this->cli->writeln("  Groups driver: {$opts->driver}");
        }

        if (isset($opts->cache)) {
            $value = $this->parseBoolean($opts->cache);
            $helper->setValue('group.cache', $value);
            $this->cli->writeln("  Caching: " . ($value ? 'enabled' : 'disabled'));
        }

        if (isset($opts->cache_lifetime)) {
            $helper->setValue('group.cache_lifetime', $opts->cache_lifetime);
            $this->cli->writeln("  Cache lifetime: {$opts->cache_lifetime} seconds");
        }
    }

    /**
     * Show current groups configuration
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function showCurrentConfig(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Current Groups Configuration');
        $this->cli->writeln('============================');
        $this->cli->writeln();

        $group = $helper->getValue('group');

        if (empty($group)) {
            $this->output->warn('No groups configuration found.');
            $this->cli->writeln('Using defaults: SQL driver with caching enabled.');
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('Backend:');
        $this->cli->writeln('  Driver:         ' . ($group['driver'] ?? 'sql'));

        $this->cli->writeln();
        $this->cli->writeln('Caching:');
        $this->cli->writeln('  Enabled:        ' . $this->formatBoolean($group['cache'] ?? true));
        $this->cli->writeln('  Lifetime:       ' . ($group['cache_lifetime'] ?? 300) . ' seconds');

        $this->cli->writeln();
        $this->cli->writeln('Notes:');
        $driver = $group['driver'] ?? 'sql';
        if ($driver === 'sql') {
            $this->cli->writeln('  SQL driver uses the main database configuration.');
            $this->cli->writeln('  Run "hordectl configure database --show" to view database settings.');
        } elseif ($driver === 'ldap') {
            $this->cli->writeln('  LDAP driver uses the main LDAP configuration.');
            $this->cli->writeln('  Run "hordectl configure ldap --show" to view LDAP settings.');
        }

        $this->cli->writeln();
    }
}
