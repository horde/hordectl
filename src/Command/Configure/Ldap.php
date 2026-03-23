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
use Exception;
use Horde\Cli\Cli as HordeCli;

/**
 * Configure LDAP connection and settings
 *
 * Provides interactive and command-line modes for configuring LDAP server
 * connections, authentication, and search settings. Supports connection
 * testing and bind authentication verification.
 *
 * Usage:
 *   hordectl configure ldap                    # Interactive mode
 *   hordectl configure ldap --show             # Show current config
 *   hordectl configure ldap --host ldap.example.com ...
 *   hordectl configure ldap --test             # Test connection
 *
 * @author Ralf Lang <lang@b1-systems.de>
 */
class Ldap implements Module, ModuleUsage
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
                '--host',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'LDAP server hostname or IP',
                ]
            ),
            new Option(
                '--port',
                [
                    'action' => 'store',
                    'type' => 'int',
                    'help' => 'LDAP server port (default: 389 for LDAP, 636 for LDAPS)',
                ]
            ),
            new Option(
                '--tls',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Use TLS encryption (true/false)',
                ]
            ),
            new Option(
                '--version',
                [
                    'action' => 'store',
                    'type' => 'int',
                    'help' => 'LDAP protocol version (2 or 3)',
                ]
            ),
            new Option(
                '--basedn',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'LDAP base DN for searches',
                ]
            ),
            new Option(
                '--binddn',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Bind DN for authentication',
                ]
            ),
            new Option(
                '--bindpw',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Bind password (warning: visible in process list)',
                ]
            ),
            new Option(
                '--uid-attr',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'LDAP attribute for username (default: uid)',
                ]
            ),
            new Option(
                '--filter',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'LDAP search filter',
                ]
            ),
            new Option(
                '--test',
                [
                    'action' => 'store_true',
                    'help' => 'Test LDAP connection and bind',
                ]
            ),
            new Option(
                '--search',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Test search for user (requires --test)',
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
                    'help' => 'Show current LDAP configuration',
                ]
            ),
        ];
    }

    /**
     * Handle the LDAP configuration command
     *
     * @param array $argv Command arguments
     * @return bool True if handled
     */
    public function handle(array $argv = []): bool
    {
        // Check if this is our command
        if (empty($argv) || $argv[0] !== 'ldap') {
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

            // Test only mode
            if (($opts->test ?? false) && !$this->hasConfigOptions($opts)) {
                return $this->testConnection($helper, $opts);
            }

            // Determine mode: interactive or CLI arguments
            if (($opts->interactive ?? false) || !$this->hasConfigOptions($opts)) {
                $this->interactiveMode($helper);
            } else {
                $this->cliMode($helper, $opts);
            }

            // Test connection if requested or in interactive mode
            $shouldTest = ($opts->test ?? false) || ($opts->interactive ?? false);
            if ($shouldTest) {
                $this->cli->writeln();
                if (!$this->testConnectionWithConfig($helper->getAll(), $opts)) {
                    $this->cli->writeln();
                    $this->output->error('Connection test failed. Configuration not saved.');
                    return false;
                }
            }

            // Save configuration
            $this->cli->writeln();
            $helper->save();
            $this->output->ok('LDAP configuration saved');
            $this->output->info('Backup created: ' . basename($helper->getBackupFile()));
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
        return isset($opts->host) || isset($opts->port) || isset($opts->tls)
               || isset($opts->version) || isset($opts->basedn) || isset($opts->binddn)
               || isset($opts->bindpw) || isset($opts->uid_attr) || isset($opts->filter);
    }

    /**
     * Interactive mode with prompts
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function interactiveMode(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('LDAP Configuration (Interactive Mode)');
        $this->cli->writeln('=====================================');
        $this->cli->writeln();

        // Server settings
        $this->cli->writeln('LDAP Server Settings:');
        $this->cli->writeln();

        $host = $this->cli->prompt(
            'LDAP server hostname:',
            $helper->getValue('ldap.hostspec') ?? 'localhost'
        );
        $helper->setValue('ldap.hostspec', $host);

        $port = $this->cli->prompt(
            'LDAP server port (389 for LDAP, 636 for LDAPS):',
            (string) ($helper->getValue('ldap.port') ?? 389)
        );
        $helper->setValue('ldap.port', (int) $port);

        $tls = $this->promptBoolean(
            'Use TLS encryption?',
            $helper->getValue('ldap.tls') ?? false
        );
        $helper->setValue('ldap.tls', $tls);

        $version = $this->cli->prompt(
            'LDAP protocol version (2 or 3):',
            (string) ($helper->getValue('ldap.version') ?? 3)
        );
        $helper->setValue('ldap.version', (int) $version);

        $this->cli->writeln();
        $this->cli->writeln('LDAP Search Configuration:');
        $this->cli->writeln();

        $basedn = $this->cli->prompt(
            'Base DN (e.g., dc=example,dc=com):',
            $helper->getValue('ldap.basedn') ?? ''
        );
        if ($basedn !== '') {
            $helper->setValue('ldap.basedn', $basedn);
        }

        $uidAttr = $this->cli->prompt(
            'Username attribute (uid, cn, sAMAccountName, etc.):',
            $helper->getValue('ldap.uid') ?? 'uid'
        );
        $helper->setValue('ldap.uid', $uidAttr);

        $filter = $this->cli->prompt(
            'Search filter (leave empty for default):',
            $helper->getValue('ldap.filter') ?? ''
        );
        if ($filter !== '') {
            $helper->setValue('ldap.filter', $filter);
        }

        $this->cli->writeln();
        $this->cli->writeln('LDAP Bind Authentication:');
        $this->cli->writeln('(Leave empty for anonymous bind)');
        $this->cli->writeln();

        $binddn = $this->cli->prompt(
            'Bind DN (e.g., cn=admin,dc=example,dc=com):',
            $helper->getValue('ldap.bind_dn') ?? ''
        );
        if ($binddn !== '') {
            $helper->setValue('ldap.bind_dn', $binddn);

            $this->cli->writeln('Bind password (leave empty to keep current):');
            $bindpw = $this->cli->passwordPrompt('Password:');
            if ($bindpw !== '') {
                $helper->setValue('ldap.bind_password', $bindpw);
            }
        } else {
            $helper->unsetValue('ldap.bind_dn');
            $helper->unsetValue('ldap.bind_password');
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
        $this->cli->writeln('Updating LDAP configuration...');
        $this->cli->writeln();

        if (isset($opts->host)) {
            $helper->setValue('ldap.hostspec', $opts->host);
            $this->cli->writeln("  LDAP host: {$opts->host}");
        }

        if (isset($opts->port)) {
            $helper->setValue('ldap.port', $opts->port);
            $this->cli->writeln("  LDAP port: {$opts->port}");
        }

        if (isset($opts->tls)) {
            $value = $this->parseBoolean($opts->tls);
            $helper->setValue('ldap.tls', $value);
            $this->cli->writeln("  TLS: " . ($value ? 'enabled' : 'disabled'));
        }

        if (isset($opts->version)) {
            $helper->setValue('ldap.version', $opts->version);
            $this->cli->writeln("  Protocol version: {$opts->version}");
        }

        if (isset($opts->basedn)) {
            $helper->setValue('ldap.basedn', $opts->basedn);
            $this->cli->writeln("  Base DN: {$opts->basedn}");
        }

        if (isset($opts->binddn)) {
            $helper->setValue('ldap.bind_dn', $opts->binddn);
            $this->cli->writeln("  Bind DN: {$opts->binddn}");
        }

        if (isset($opts->bindpw)) {
            $this->output->warn('Bind password in command line is visible in process list');
            $helper->setValue('ldap.bind_password', $opts->bindpw);
            $this->cli->writeln('  Bind password: ********');
        }

        if (isset($opts->uid_attr)) {
            $helper->setValue('ldap.uid', $opts->uid_attr);
            $this->cli->writeln("  UID attribute: {$opts->uid_attr}");
        }

        if (isset($opts->filter)) {
            $helper->setValue('ldap.filter', $opts->filter);
            $this->cli->writeln("  Search filter: {$opts->filter}");
        }
    }

    /**
     * Show current LDAP configuration
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function showCurrentConfig(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Current LDAP Configuration');
        $this->cli->writeln('==========================');
        $this->cli->writeln();

        $ldap = $helper->getValue('ldap');

        if (empty($ldap)) {
            $this->output->warn('No LDAP configuration found.');
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('Server:');
        $this->cli->writeln('  Host:             ' . ($ldap['hostspec'] ?? '(not set)'));
        $this->cli->writeln('  Port:             ' . ($ldap['port'] ?? 389));
        $this->cli->writeln('  TLS:              ' . $this->formatBoolean($ldap['tls'] ?? false));
        $this->cli->writeln('  Protocol version: ' . ($ldap['version'] ?? 3));

        $this->cli->writeln();
        $this->cli->writeln('Search:');
        $this->cli->writeln('  Base DN:          ' . ($ldap['basedn'] ?? '(not set)'));
        $this->cli->writeln('  UID attribute:    ' . ($ldap['uid'] ?? 'uid'));
        $this->cli->writeln('  Search filter:    ' . ($ldap['filter'] ?? '(default)'));

        $this->cli->writeln();
        $this->cli->writeln('Authentication:');
        $this->cli->writeln('  Bind DN:          ' . ($ldap['bind_dn'] ?? '(anonymous)'));
        $this->cli->writeln('  Bind password:    ' . (isset($ldap['bind_password']) && $ldap['bind_password'] !== '' ? '********' : '(not set)'));

        $this->cli->writeln();
    }

    /**
     * Test connection with current configuration
     *
     * @param ConfigHelper $helper Configuration helper
     * @param object $opts Parsed options
     * @return bool True on success
     */
    private function testConnection(ConfigHelper $helper, object $opts): bool
    {
        $this->cli->writeln();
        $this->cli->writeln('Testing LDAP connection...');
        $this->cli->writeln();

        return $this->testConnectionWithConfig($helper->getAll(), $opts);
    }

    /**
     * Test LDAP connection with given configuration
     *
     * @param array $config Configuration array
     * @param object $opts Command options
     * @return bool True on success
     */
    private function testConnectionWithConfig(array $config, object $opts): bool
    {
        if (!isset($config['ldap'])) {
            $this->output->error('No LDAP configuration found');
            return false;
        }

        $ldap = $config['ldap'];

        // Check for ldap extension
        if (!extension_loaded('ldap')) {
            $this->output->error('LDAP extension not loaded');
            $this->cli->writeln('  Install php-ldap extension to use LDAP functionality');
            return false;
        }

        // Validate required fields
        $host = $ldap['hostspec'] ?? null;
        if (!$host) {
            $this->output->error('LDAP host not configured');
            return false;
        }

        $port = $ldap['port'] ?? 389;
        $tls = $ldap['tls'] ?? false;

        try {
            // Build connection string
            $uri = "ldap://{$host}:{$port}";
            $this->cli->writeln("  Connecting to: {$uri}");
            if ($tls) {
                $this->cli->writeln("  TLS: enabled");
            }

            // Connect
            $conn = @ldap_connect($uri);
            if ($conn === false) {
                $this->output->error('Failed to connect to LDAP server');
                return false;
            }

            // Set options
            $version = $ldap['version'] ?? 3;
            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, $version);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

            // Start TLS if requested
            if ($tls) {
                if (!@ldap_start_tls($conn)) {
                    $this->output->error('Failed to start TLS');
                    $this->cli->writeln('  Error: ' . ldap_error($conn));
                    ldap_unbind($conn);
                    return false;
                }
            }

            // Test bind
            $bindDn = $ldap['bind_dn'] ?? null;
            $bindPw = $ldap['bind_password'] ?? null;

            if ($bindDn) {
                $this->cli->writeln("  Binding as: {$bindDn}");
                if (!@ldap_bind($conn, $bindDn, $bindPw)) {
                    $this->output->error('LDAP bind failed');
                    $this->cli->writeln('  Error: ' . ldap_error($conn));
                    ldap_unbind($conn);
                    return false;
                }
            } else {
                $this->cli->writeln('  Binding anonymously');
                if (!@ldap_bind($conn)) {
                    $this->output->error('LDAP anonymous bind failed');
                    $this->cli->writeln('  Error: ' . ldap_error($conn));
                    ldap_unbind($conn);
                    return false;
                }
            }

            $this->output->ok('LDAP connection and bind successful');

            // Test search if requested
            if (isset($opts->search)) {
                $this->testSearch($conn, $ldap, $opts->search);
            }

            ldap_unbind($conn);
            return true;
        } catch (Exception $e) {
            $this->output->error('LDAP test failed');
            $this->cli->writeln('  Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Test LDAP search
     *
     * @param resource $conn LDAP connection
     * @param array $ldap LDAP configuration
     * @param string $searchUser User to search for
     */
    private function testSearch($conn, array $ldap, string $searchUser): void
    {
        $this->cli->writeln();
        $this->cli->writeln("Testing search for user: {$searchUser}");

        $baseDn = $ldap['basedn'] ?? '';
        if (empty($baseDn)) {
            $this->output->warn('No base DN configured');
            return;
        }

        $uidAttr = $ldap['uid'] ?? 'uid';
        $filter = $ldap['filter'] ?? "({$uidAttr}=*)";

        // Replace %u with username
        $filter = str_replace('%u', $searchUser, $filter);

        // If filter doesn't contain username attribute, add it
        if (strpos($filter, $uidAttr) === false) {
            $filter = "(&{$filter}({$uidAttr}={$searchUser}))";
        }

        $this->cli->writeln("  Base DN: {$baseDn}");
        $this->cli->writeln("  Filter: {$filter}");

        $result = @ldap_search($conn, $baseDn, $filter);
        if ($result === false) {
            $this->output->error('Search failed');
            $this->cli->writeln('    Error: ' . ldap_error($conn));
            return;
        }

        $entries = ldap_get_entries($conn, $result);
        $count = $entries['count'] ?? 0;

        if ($count === 0) {
            $this->output->warn("No entries found for user: {$searchUser}");
        } else {
            $this->output->ok("Found {$count} entry/entries");
            if ($count > 0 && isset($entries[0]['dn'])) {
                $this->cli->writeln("    DN: {$entries[0]['dn']}");
            }
        }
    }
}
