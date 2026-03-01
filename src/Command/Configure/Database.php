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
use PDO;
use PDOException;
use RuntimeException;

/**
 * Configure database settings
 *
 * Provides interactive and command-line modes for configuring Horde database
 * connection settings. Supports testing connections before saving and
 * displays current configuration.
 *
 * Usage:
 *   hordectl configure database                    # Interactive mode
 *   hordectl configure database --show             # Show current config
 *   hordectl configure database --type mysql ...   # CLI mode
 *   hordectl configure database --test             # Test current config
 *
 * @author Ralf Lang <lang@b1-systems.de>
 */
class Database implements Module, ModuleUsage
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

    public function getPositionalArgs(): array
    {
        return ['database', 'db'];
    }

    public function getBaseOptions()
    {
        return [
            new Option(
                '--type',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Database type (mysql, pgsql, sqlite)'
                ]
            ),
            new Option(
                '--host',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Database host'
                ]
            ),
            new Option(
                '--port',
                [
                    'action' => 'store',
                    'type' => 'int',
                    'help' => 'Database port'
                ]
            ),
            new Option(
                '--username',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Database username'
                ]
            ),
            new Option(
                '--password',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Database password (warning: visible in process list)'
                ]
            ),
            new Option(
                '--database',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Database name'
                ]
            ),
            new Option(
                '--charset',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Character set (default: utf8mb4)'
                ]
            ),
            new Option(
                '--protocol',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Connection protocol (tcp, unix, etc.)'
                ]
            ),
            new Option(
                '--socket',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Unix socket path (alternative to host/port)'
                ]
            ),
            new Option(
                '--test',
                [
                    'action' => 'store_true',
                    'help' => 'Test database connection (with current or provided settings)'
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
                    'help' => 'Show current database configuration'
                ]
            ),
        ];
    }

    /**
     * Handle the database configuration command
     *
     * @param array $argv Command arguments
     * @return bool True if handled
     */
    public function handle(array $argv = []): bool
    {
        // Check if this is our command
        if (empty($argv) || ($argv[0] !== 'database' && $argv[0] !== 'db')) {
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

            // Test only mode
            if (($opts->test ?? false) && !$this->hasConfigOptions($opts)) {
                return $this->testConnection($helper);
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
                if (!$this->testConnectionWithConfig($helper->getAll())) {
                    $this->cli->writeln();
                    $this->cli->message('Connection test failed. Configuration not saved.', 'cli.error');
                    return false;
                }
            }

            // Save configuration
            $this->cli->writeln();
            $helper->save();
            $this->cli->message('✓ Database configuration saved', 'cli.success');
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
        return isset($opts->type) || isset($opts->host) || isset($opts->port) ||
               isset($opts->username) || isset($opts->password) || isset($opts->database) ||
               isset($opts->charset) || isset($opts->protocol) || isset($opts->socket);
    }

    /**
     * Interactive mode with prompts
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function interactiveMode(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Database Configuration (Interactive Mode)');
        $this->cli->writeln('=========================================');
        $this->cli->writeln();

        // Database type
        $currentType = $helper->getValue('sql.phptype') ?? 'mysql';
        $type = $this->cli->prompt(
            'Database type [mysql/pgsql/sqlite]:',
            $currentType
        );
        $helper->setValue('sql.phptype', $type);

        // For SQLite, only need the database path
        if ($type === 'sqlite') {
            $database = $this->cli->prompt(
                'Database file path:',
                $helper->getValue('sql.database') ?? '/var/www/horde/horde.db'
            );
            $helper->setValue('sql.database', $database);
            return;
        }

        // Host or socket
        $useSocket = $this->cli->prompt(
            'Use Unix socket? [y/N]:',
            'n'
        );

        if (strtolower($useSocket) === 'y') {
            $socket = $this->cli->prompt(
                'Socket path:',
                $helper->getValue('sql.socket') ?? '/var/run/mysqld/mysqld.sock'
            );
            $helper->setValue('sql.socket', $socket);
            $helper->unsetValue('sql.hostspec');
            $helper->unsetValue('sql.port');
        } else {
            $host = $this->cli->prompt(
                'Database host:',
                $helper->getValue('sql.hostspec') ?? 'localhost'
            );
            $helper->setValue('sql.hostspec', $host);

            $port = $this->cli->prompt(
                'Database port (leave empty for default):',
                (string)($helper->getValue('sql.port') ?? '')
            );
            if ($port !== '') {
                $helper->setValue('sql.port', (int)$port);
            } else {
                $helper->unsetValue('sql.port');
            }

            $helper->unsetValue('sql.socket');
        }

        // Username
        $username = $this->cli->prompt(
            'Database username:',
            $helper->getValue('sql.username') ?? 'horde'
        );
        $helper->setValue('sql.username', $username);

        // Password
        $this->cli->writeln('Database password (leave empty to keep current):');
        $password = $this->cli->passwordPrompt('Password:');
        if ($password !== '') {
            $helper->setValue('sql.password', $password);
        }

        // Database name
        $database = $this->cli->prompt(
            'Database name:',
            $helper->getValue('sql.database') ?? 'horde'
        );
        $helper->setValue('sql.database', $database);

        // Character set
        $charset = $this->cli->prompt(
            'Character set:',
            $helper->getValue('sql.charset') ?? 'utf8mb4'
        );
        $helper->setValue('sql.charset', $charset);

        // Protocol (optional)
        $protocol = $this->cli->prompt(
            'Protocol (leave empty for default):',
            $helper->getValue('sql.protocol') ?? ''
        );
        if ($protocol !== '') {
            $helper->setValue('sql.protocol', $protocol);
        } else {
            $helper->unsetValue('sql.protocol');
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
        $this->cli->writeln('Updating database configuration...');
        $this->cli->writeln();

        if (isset($opts->type)) {
            $helper->setValue('sql.phptype', $opts->type);
            $this->cli->writeln("  Database type: {$opts->type}");
        }

        if (isset($opts->host)) {
            $helper->setValue('sql.hostspec', $opts->host);
            $helper->unsetValue('sql.socket');
            $this->cli->writeln("  Host: {$opts->host}");
        }

        if (isset($opts->port)) {
            $helper->setValue('sql.port', $opts->port);
            $this->cli->writeln("  Port: {$opts->port}");
        }

        if (isset($opts->socket)) {
            $helper->setValue('sql.socket', $opts->socket);
            $helper->unsetValue('sql.hostspec');
            $helper->unsetValue('sql.port');
            $this->cli->writeln("  Socket: {$opts->socket}");
        }

        if (isset($opts->username)) {
            $helper->setValue('sql.username', $opts->username);
            $this->cli->writeln("  Username: {$opts->username}");
        }

        if (isset($opts->password)) {
            $this->cli->message(
                'Warning: Password in command line is visible in process list',
                'cli.warning'
            );
            $helper->setValue('sql.password', $opts->password);
            $this->cli->writeln('  Password: ********');
        }

        if (isset($opts->database)) {
            $helper->setValue('sql.database', $opts->database);
            $this->cli->writeln("  Database: {$opts->database}");
        }

        if (isset($opts->charset)) {
            $helper->setValue('sql.charset', $opts->charset);
            $this->cli->writeln("  Charset: {$opts->charset}");
        }

        if (isset($opts->protocol)) {
            $helper->setValue('sql.protocol', $opts->protocol);
            $this->cli->writeln("  Protocol: {$opts->protocol}");
        }
    }

    /**
     * Show current database configuration
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function showCurrentConfig(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Current Database Configuration');
        $this->cli->writeln('==============================');
        $this->cli->writeln();

        $sql = $helper->getValue('sql');

        if (empty($sql)) {
            $this->cli->message('No database configuration found.', 'cli.warning');
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('  Type:      ' . ($sql['phptype'] ?? '(not set)'));

        if (isset($sql['socket'])) {
            $this->cli->writeln('  Socket:    ' . $sql['socket']);
        } else {
            $this->cli->writeln('  Host:      ' . ($sql['hostspec'] ?? '(not set)'));
            $this->cli->writeln('  Port:      ' . ($sql['port'] ?? '(default)'));
        }

        $this->cli->writeln('  Username:  ' . ($sql['username'] ?? '(not set)'));
        $this->cli->writeln('  Password:  ' . (isset($sql['password']) && $sql['password'] !== '' ? '********' : '(not set)'));
        $this->cli->writeln('  Database:  ' . ($sql['database'] ?? '(not set)'));
        $this->cli->writeln('  Charset:   ' . ($sql['charset'] ?? '(not set)'));

        if (isset($sql['protocol'])) {
            $this->cli->writeln('  Protocol:  ' . $sql['protocol']);
        }

        $this->cli->writeln();
    }

    /**
     * Test connection with current configuration
     *
     * @param ConfigHelper $helper Configuration helper
     * @return bool True on success
     */
    private function testConnection(ConfigHelper $helper): bool
    {
        $this->cli->writeln();
        $this->cli->writeln('Testing database connection...');
        $this->cli->writeln();

        return $this->testConnectionWithConfig($helper->getAll());
    }

    /**
     * Test database connection with given configuration
     *
     * @param array $config Configuration array
     * @return bool True on success
     */
    private function testConnectionWithConfig(array $config): bool
    {
        if (!isset($config['sql'])) {
            $this->cli->message('✗ No database configuration found', 'cli.error');
            return false;
        }

        $sql = $config['sql'];

        // Validate required fields
        $type = $sql['phptype'] ?? null;
        if (!$type) {
            $this->cli->message('✗ Database type not configured', 'cli.error');
            return false;
        }

        // SQLite only needs database path
        if ($type === 'sqlite') {
            return $this->testSqliteConnection($sql);
        }

        // Other databases need more configuration
        if (!isset($sql['database'])) {
            $this->cli->message('✗ Database name not configured', 'cli.error');
            return false;
        }

        if (!isset($sql['username'])) {
            $this->cli->message('✗ Database username not configured', 'cli.error');
            return false;
        }

        try {
            // Build DSN
            $dsn = $this->buildDsn($sql);

            $this->cli->writeln("  Connecting to: {$dsn}");
            $this->cli->writeln("  Username: {$sql['username']}");
            $this->cli->writeln();

            // Attempt connection
            $pdo = new PDO(
                $dsn,
                $sql['username'],
                $sql['password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Test query
            $pdo->query('SELECT 1');

            $this->cli->message('✓ Database connection successful', 'cli.success');
            return true;
        } catch (PDOException $e) {
            $this->cli->message('✗ Database connection failed', 'cli.error');
            $this->cli->writeln('  Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Test SQLite connection
     *
     * @param array $sql SQL configuration
     * @return bool True on success
     */
    private function testSqliteConnection(array $sql): bool
    {
        $database = $sql['database'] ?? null;
        if (!$database) {
            $this->cli->message('✗ Database file path not configured', 'cli.error');
            return false;
        }

        try {
            $dsn = "sqlite:{$database}";
            $this->cli->writeln("  Database file: {$database}");
            $this->cli->writeln();

            $pdo = new PDO($dsn);
            $pdo->query('SELECT 1');

            $this->cli->message('✓ SQLite database accessible', 'cli.success');
            return true;
        } catch (PDOException $e) {
            $this->cli->message('✗ SQLite connection failed', 'cli.error');
            $this->cli->writeln('  Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build PDO DSN from configuration
     *
     * @param array $sql SQL configuration
     * @return string DSN
     */
    private function buildDsn(array $sql): string
    {
        $type = $sql['phptype'];
        $dsn = $type . ':';

        // Socket or host/port
        if (isset($sql['socket'])) {
            $dsn .= 'unix_socket=' . $sql['socket'] . ';';
        } else {
            $host = $sql['hostspec'] ?? 'localhost';
            $dsn .= 'host=' . $host . ';';

            if (isset($sql['port'])) {
                $dsn .= 'port=' . $sql['port'] . ';';
            }
        }

        // Database name
        $dsn .= 'dbname=' . $sql['database'];

        // Charset
        if (isset($sql['charset'])) {
            $dsn .= ';charset=' . $sql['charset'];
        }

        return $dsn;
    }
}
