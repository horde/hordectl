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
 * Configure tokens system
 *
 * Provides interactive and command-line modes for configuring Horde tokens
 * backend. Tokens are used for authentication, API access, and temporary
 * secure storage.
 *
 * Usage:
 *   hordectl configure tokens                    # Interactive mode
 *   hordectl configure tokens --show             # Show current config
 *   hordectl configure tokens --driver sql ...   # CLI mode
 *
 * @author Ralf Lang <lang@b1-systems.de>
 */
class Tokens implements Module, ModuleUsage
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

    public function getBaseOptions()
    {
        return [
            new Option(
                '--driver',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Token storage driver (sql, file, mock)'
                ]
            ),
            new Option(
                '--secret',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Secret key for token generation (auto-generated if not provided)'
                ]
            ),
            new Option(
                '--expiration',
                [
                    'action' => 'store',
                    'type' => 'int',
                    'help' => 'Default token expiration in seconds'
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
                    'help' => 'Show current token configuration'
                ]
            ),
        ];
    }

    /**
     * Handle the tokens configuration command
     *
     * @param array $argv Command arguments
     * @return bool True if handled
     */
    public function handle(array $argv = []): bool
    {
        // Check if this is our command
        if (empty($argv) || $argv[0] !== 'tokens') {
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
            $this->cli->message('✓ Token configuration saved', 'cli.success');
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
        return isset($opts->driver) || isset($opts->secret) || isset($opts->expiration);
    }

    /**
     * Interactive mode with prompts
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function interactiveMode(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Token System Configuration (Interactive Mode)');
        $this->cli->writeln('=============================================');
        $this->cli->writeln();

        $this->cli->writeln('Available token drivers:');
        $this->cli->writeln('  sql  - SQL database backend (uses main database)');
        $this->cli->writeln('  file - File-based storage');
        $this->cli->writeln('  mock - Mock driver for testing');
        $this->cli->writeln();

        $currentDriver = $helper->getValue('token.driver') ?? 'sql';
        $driver = $this->cli->prompt(
            'Token storage driver:',
            $currentDriver
        );
        $helper->setValue('token.driver', $driver);

        $this->cli->writeln();
        $this->cli->writeln('Security settings:');
        $this->cli->writeln();

        // Secret key
        $hasSecret = $helper->hasValue('token.secret');
        if ($hasSecret) {
            $this->cli->writeln('Secret key: (already configured)');
            $updateSecret = $this->promptBoolean(
                'Generate new secret key?',
                false
            );
            if ($updateSecret) {
                $secret = $this->generateSecret();
                $helper->setValue('token.secret', $secret);
                $this->cli->message('Generated new secret key', 'cli.success');
            }
        } else {
            $this->cli->writeln('No secret key configured. A secret key is required for token generation.');
            $generateSecret = $this->promptBoolean(
                'Generate secret key automatically?',
                true
            );
            if ($generateSecret) {
                $secret = $this->generateSecret();
                $helper->setValue('token.secret', $secret);
                $this->cli->message('Generated secret key', 'cli.success');
            } else {
                $secret = $this->cli->passwordPrompt('Enter secret key:');
                if ($secret !== '') {
                    $helper->setValue('token.secret', $secret);
                }
            }
        }

        $this->cli->writeln();
        $this->cli->writeln('Expiration settings:');
        $this->cli->writeln();

        $currentExpiration = $helper->getValue('token.expiration') ?? 86400;
        $expiration = $this->cli->prompt(
            'Default token expiration in seconds (86400 = 1 day):',
            (string)$currentExpiration
        );
        $helper->setValue('token.expiration', (int)$expiration);

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
        $this->cli->writeln('Updating token configuration...');
        $this->cli->writeln();

        if (isset($opts->driver)) {
            $helper->setValue('token.driver', $opts->driver);
            $this->cli->writeln("  Token driver: {$opts->driver}");
        }

        if (isset($opts->secret)) {
            if ($opts->secret === 'generate') {
                $secret = $this->generateSecret();
                $helper->setValue('token.secret', $secret);
                $this->cli->writeln('  Secret: (generated)');
            } else {
                $this->cli->message(
                    'Warning: Secret in command line is visible in process list',
                    'cli.warning'
                );
                $helper->setValue('token.secret', $opts->secret);
                $this->cli->writeln('  Secret: ********');
            }
        }

        if (isset($opts->expiration)) {
            $helper->setValue('token.expiration', $opts->expiration);
            $this->cli->writeln("  Expiration: {$opts->expiration} seconds");
        }
    }

    /**
     * Show current token configuration
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function showCurrentConfig(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Current Token Configuration');
        $this->cli->writeln('===========================');
        $this->cli->writeln();

        $token = $helper->getValue('token');

        if (empty($token)) {
            $this->cli->message('No token configuration found.', 'cli.warning');
            $this->cli->writeln('Using defaults: SQL driver with 1-day expiration.');
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('Backend:');
        $this->cli->writeln('  Driver:         ' . ($token['driver'] ?? 'sql'));

        $this->cli->writeln();
        $this->cli->writeln('Security:');
        $this->cli->writeln('  Secret key:     ' . ($token['secret'] ?? false ? '(configured)' : '(not set)'));

        $this->cli->writeln();
        $this->cli->writeln('Expiration:');
        $expiration = $token['expiration'] ?? 86400;
        $this->cli->writeln('  Default:        ' . $expiration . ' seconds (' . $this->formatDuration($expiration) . ')');

        $this->cli->writeln();
        $this->cli->writeln('Notes:');
        $driver = $token['driver'] ?? 'sql';
        if ($driver === 'sql') {
            $this->cli->writeln('  SQL driver uses the main database configuration.');
            $this->cli->writeln('  Run "hordectl configure database --show" to view database settings.');
        }

        if (!isset($token['secret']) || empty($token['secret'])) {
            $this->cli->writeln();
            $this->cli->message('  Warning: No secret key configured!', 'cli.warning');
            $this->cli->writeln('  Token generation will not work without a secret key.');
            $this->cli->writeln('  Run "hordectl configure tokens" to generate one.');
        }

        $this->cli->writeln();
    }

    /**
     * Generate a random secret key
     *
     * @return string Generated secret
     */
    private function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Format duration in human-readable form
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        } elseif ($seconds < 3600) {
            $minutes = round($seconds / 60);
            return "{$minutes}m";
        } elseif ($seconds < 86400) {
            $hours = round($seconds / 3600);
            return "{$hours}h";
        } else {
            $days = round($seconds / 86400);
            return "{$days}d";
        }
    }
}
