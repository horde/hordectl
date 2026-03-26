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
use Horde\Hordectl\ConfigHelper;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Horde\Argv\Option;
use RuntimeException;
use Horde\Cli\Cli as HordeCli;

/**
 * Configure authentication settings
 *
 * @author Ralf Lang <ralf.lang@ralf-lang.de>
 */
class Auth implements Module, ModuleUsage
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

    public function getPositionalArgs(): array
    {
        return ['authentication', 'auth'];
    }

    public function getBaseOptions()
    {
        return [
            new Option(
                '--driver',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Auth driver (sql, ldap, etc.)',
                ]
            ),
            new Option(
                '--encryption',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Password encryption method (ssha, bcrypt, etc.)',
                ]
            ),
            new Option(
                '--show',
                [
                    'action' => 'store_true',
                    'help' => 'Show current authentication configuration',
                ]
            ),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || ($argv[0] !== 'authentication' && $argv[0] !== 'auth')) {
            return false;
        }

        $target = $this->requireConfigureCapability();

        [$opts, $args] = $this->handleCommandline($argv);

        try {
            $installDir = $target->hordeInstallDir;
            if (!$installDir) {
                $this->cli->fatal("Target '{$target->name}' has no installation directory configured.");
            }

            $helper = new ConfigHelper('horde', $installDir);

            if ($opts->show ?? false) {
                $this->showCurrentConfig($helper);
                return true;
            }

            // Determine mode: interactive or CLI arguments
            if (!isset($opts->driver)) {
                $this->interactiveMode($helper);
            } else {
                $this->cliMode($helper, $opts);
            }

            $this->cli->writeln();
            $helper->save();
            $this->output->ok('Authentication configuration saved');
            $this->cli->writeln();

            return true;
        } catch (RuntimeException $e) {
            $this->cli->fatal($e->getMessage());
            return false;
        }
    }

    private function interactiveMode(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Authentication Configuration (Interactive Mode)');
        $this->cli->writeln('===============================================');
        $this->cli->writeln();

        // Save old driver BEFORE any prompts
        $oldDriver = $helper->getValue('auth.driver');

        // Auth driver
        $currentDriver = $oldDriver ?? 'sql';
        $this->cli->writeln('Available authentication drivers:');
        $this->cli->writeln('  sql       - SQL database authentication');
        $this->cli->writeln('  ldap      - LDAP directory authentication');
        $this->cli->writeln('  composite - Multiple authentication backends');
        $this->cli->writeln();

        $driver = $this->cli->prompt(
            prompt: 'Authentication driver:',
            choices: null,
            default: $currentDriver
        );
        $helper->setValue('auth.driver', $driver);

        // Check if driver changed
        $driverChanged = ($oldDriver !== $driver);

        // Driver-specific configuration
        if ($driver === 'sql') {
            $helper->setValue('auth.params.driverconfig', 'horde');
            $helper->setValue('auth.params.table', 'horde_users');
            $helper->setValue('auth.params.username_field', 'user_uid');
            $helper->setValue('auth.params.password_field', 'user_pass');

            $this->cli->writeln();
            $this->cli->writeln('SQL authentication will use:');
            $this->cli->writeln('  Database: horde (main database)');
            $this->cli->writeln('  Table: horde_users');
            $this->cli->writeln();

            // Password encryption - use saved value if driver unchanged, else default
            $encryptionDefault = $driverChanged
                ? 'ssha'
                : ($helper->getValue('auth.params.encryption') ?? 'ssha');

            $this->cli->writeln('Password encryption methods:');
            $this->cli->writeln('  ssha      - Salted SHA (recommended)');
            $this->cli->writeln('  bcrypt    - BCrypt (PHP 5.5+)');
            $this->cli->writeln('  sha256    - SHA-256');
            $this->cli->writeln();

            $encryption = $this->cli->prompt(
                prompt: 'Password encryption:',
                choices: null,
                default: $encryptionDefault
            );
            $helper->setValue('auth.params.encryption', $encryption);
        }
    }

    private function cliMode(ConfigHelper $helper, object $opts): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Updating authentication configuration...');
        $this->cli->writeln();

        if (isset($opts->driver)) {
            $helper->setValue('auth.driver', $opts->driver);
            $this->cli->writeln("  Driver: {$opts->driver}");

            // Set SQL-specific defaults
            if ($opts->driver === 'sql') {
                $helper->setValue('auth.params.driverconfig', 'horde');
                $helper->setValue('auth.params.table', 'horde_users');
                $helper->setValue('auth.params.username_field', 'user_uid');
                $helper->setValue('auth.params.password_field', 'user_pass');
                $this->cli->writeln("  Using SQL table: horde_users");
            }
        }

        if (isset($opts->encryption)) {
            $helper->setValue('auth.params.encryption', $opts->encryption);
            $this->cli->writeln("  Encryption: {$opts->encryption}");
        }
    }

    private function showCurrentConfig(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Current Authentication Configuration');
        $this->cli->writeln('===================================');
        $this->cli->writeln();

        $auth = $helper->getValue('auth');

        if (empty($auth)) {
            $this->output->warn('No authentication configuration found.');
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('Driver: ' . ($auth['driver'] ?? '(not set)'));

        if (isset($auth['params']['encryption'])) {
            $this->cli->writeln('Encryption: ' . $auth['params']['encryption']);
        }

        if (isset($auth['params']['table'])) {
            $this->cli->writeln('SQL Table: ' . $auth['params']['table']);
        }

        $this->cli->writeln();
    }

    public function getUsage()
    {
        return 'Configure authentication driver';
    }

    public function getSummary()
    {
        return 'Configure authentication settings';
    }
}
