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
 * Configure session handler settings
 *
 * Provides interactive and command-line modes for configuring Horde session
 * handler settings. Supports multiple backends (file, SQL, Memcache, etc.)
 * and allows testing session storage.
 *
 * Usage:
 *   hordectl configure sessionhandler                    # Interactive mode
 *   hordectl configure session --show                    # Show current config
 *   hordectl configure session --type file ...           # CLI mode
 *
 * @author Ralf Lang <lang@b1-systems.de>
 */
class SessionHandler implements Module, ModuleUsage
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
                '--type',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Session handler type (builtin, file, sql, memcache, memcached, external)'
                ]
            ),
            new Option(
                '--path',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Session save path (for file and builtin types)'
                ]
            ),
            new Option(
                '--memcache',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Memcache server list (comma-separated host:port)'
                ]
            ),
            new Option(
                '--timeout',
                [
                    'action' => 'store',
                    'type' => 'int',
                    'help' => 'Session timeout in seconds'
                ]
            ),
            new Option(
                '--gc-maxlifetime',
                [
                    'action' => 'store',
                    'type' => 'int',
                    'help' => 'Garbage collection maximum lifetime in seconds'
                ]
            ),
            new Option(
                '--gc-probability',
                [
                    'action' => 'store',
                    'type' => 'int',
                    'help' => 'Garbage collection probability (0-100)'
                ]
            ),
            new Option(
                '--use-cookies',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Use cookies for session ID (true/false)'
                ]
            ),
            new Option(
                '--cookie-secure',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Send cookie only over secure connections (true/false)'
                ]
            ),
            new Option(
                '--cookie-httponly',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Set HttpOnly flag on cookies (true/false)'
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
                    'help' => 'Show current session configuration'
                ]
            ),
        ];
    }

    /**
     * Handle the session handler configuration command
     *
     * @param array $argv Command arguments
     * @return bool True if handled
     */
    public function handle(array $argv = []): bool
    {
        // Check if this is our command
        if (empty($argv) || ($argv[0] !== 'sessionhandler' && $argv[0] !== 'session')) {
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
            $this->cli->message('✓ Session handler configuration saved', 'cli.success');
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
        return isset($opts->type) || isset($opts->path) || isset($opts->memcache) ||
               isset($opts->timeout) || isset($opts->gc_maxlifetime) || isset($opts->gc_probability) ||
               isset($opts->use_cookies) || isset($opts->cookie_secure) || isset($opts->cookie_httponly);
    }

    /**
     * Interactive mode with prompts
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function interactiveMode(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Session Handler Configuration (Interactive Mode)');
        $this->cli->writeln('================================================');
        $this->cli->writeln();

        // Session handler type
        $currentType = $helper->getValue('sessionhandler.type') ?? 'builtin';
        $this->cli->writeln('Available session handler types:');
        $this->cli->writeln('  builtin   - PHP built-in session handler');
        $this->cli->writeln('  file      - File-based session handler');
        $this->cli->writeln('  sql       - Database session handler (uses main database)');
        $this->cli->writeln('  memcache  - Memcache session handler');
        $this->cli->writeln('  memcached - Memcached session handler (different extension)');
        $this->cli->writeln('  external  - External session handler');
        $this->cli->writeln();

        $type = $this->cli->prompt(
            'Session handler type:',
            $currentType
        );
        $helper->setValue('sessionhandler.type', $type);

        // Type-specific configuration
        if ($type === 'file' || $type === 'builtin') {
            $defaultPath = $helper->getValue('sessionhandler.path') ?? '/tmp';
            $path = $this->cli->prompt(
                'Session save path:',
                $defaultPath
            );
            $helper->setValue('sessionhandler.path', $path);
        } elseif ($type === 'memcache' || $type === 'memcached') {
            $this->cli->writeln();
            $this->cli->writeln('Memcache server configuration:');
            $this->cli->writeln('Format: host:port (comma-separated for multiple servers)');
            $this->cli->writeln('Example: localhost:11211,cache2.example.com:11211');
            $this->cli->writeln();

            $currentMemcache = $this->formatMemcacheForDisplay($helper);
            $memcache = $this->cli->prompt(
                'Memcache servers:',
                $currentMemcache
            );
            $this->parseMemcacheServers($helper, $memcache);
        }

        $this->cli->writeln();
        $this->cli->writeln('Session timeout settings:');
        $this->cli->writeln();

        // Timeout
        $currentTimeout = $helper->getValue('sessionhandler.timeout') ?? 0;
        $timeout = $this->cli->prompt(
            'Session timeout in seconds (0 for default):',
            (string)$currentTimeout
        );
        if ($timeout !== '0') {
            $helper->setValue('sessionhandler.timeout', (int)$timeout);
        } else {
            $helper->unsetValue('sessionhandler.timeout');
        }

        // GC maxlifetime
        $currentMaxlifetime = $helper->getValue('sessionhandler.gc_maxlifetime') ?? 1440;
        $maxlifetime = $this->cli->prompt(
            'Garbage collection max lifetime in seconds:',
            (string)$currentMaxlifetime
        );
        $helper->setValue('sessionhandler.gc_maxlifetime', (int)$maxlifetime);

        // GC probability
        $currentProbability = $helper->getValue('sessionhandler.gc_probability') ?? 1;
        $probability = $this->cli->prompt(
            'Garbage collection probability (0-100):',
            (string)$currentProbability
        );
        $helper->setValue('sessionhandler.gc_probability', (int)$probability);

        $this->cli->writeln();
        $this->cli->writeln('Cookie settings:');
        $this->cli->writeln();

        // Use cookies
        $useCookies = $this->promptBoolean(
            'Use cookies for session ID?',
            $helper->getValue('sessionhandler.use_only_cookies') ?? true
        );
        $helper->setValue('sessionhandler.use_only_cookies', $useCookies);

        // Cookie secure
        $cookieSecure = $this->promptBoolean(
            'Send cookies only over HTTPS?',
            $helper->getValue('sessionhandler.cookie_secure') ?? false
        );
        $helper->setValue('sessionhandler.cookie_secure', $cookieSecure);

        // Cookie httponly
        $cookieHttponly = $this->promptBoolean(
            'Set HttpOnly flag on cookies?',
            $helper->getValue('sessionhandler.cookie_httponly') ?? true
        );
        $helper->setValue('sessionhandler.cookie_httponly', $cookieHttponly);
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
        $this->cli->writeln('Updating session handler configuration...');
        $this->cli->writeln();

        if (isset($opts->type)) {
            $helper->setValue('sessionhandler.type', $opts->type);
            $this->cli->writeln("  Session handler type: {$opts->type}");
        }

        if (isset($opts->path)) {
            $helper->setValue('sessionhandler.path', $opts->path);
            $this->cli->writeln("  Session save path: {$opts->path}");
        }

        if (isset($opts->memcache)) {
            $this->parseMemcacheServers($helper, $opts->memcache);
            $this->cli->writeln("  Memcache servers: {$opts->memcache}");
        }

        if (isset($opts->timeout)) {
            $helper->setValue('sessionhandler.timeout', $opts->timeout);
            $this->cli->writeln("  Timeout: {$opts->timeout} seconds");
        }

        if (isset($opts->gc_maxlifetime)) {
            $helper->setValue('sessionhandler.gc_maxlifetime', $opts->gc_maxlifetime);
            $this->cli->writeln("  GC max lifetime: {$opts->gc_maxlifetime} seconds");
        }

        if (isset($opts->gc_probability)) {
            $helper->setValue('sessionhandler.gc_probability', $opts->gc_probability);
            $this->cli->writeln("  GC probability: {$opts->gc_probability}");
        }

        if (isset($opts->use_cookies)) {
            $value = $this->parseBoolean($opts->use_cookies);
            $helper->setValue('sessionhandler.use_only_cookies', $value);
            $this->cli->writeln("  Use cookies: " . ($value ? 'yes' : 'no'));
        }

        if (isset($opts->cookie_secure)) {
            $value = $this->parseBoolean($opts->cookie_secure);
            $helper->setValue('sessionhandler.cookie_secure', $value);
            $this->cli->writeln("  Secure cookies: " . ($value ? 'yes' : 'no'));
        }

        if (isset($opts->cookie_httponly)) {
            $value = $this->parseBoolean($opts->cookie_httponly);
            $helper->setValue('sessionhandler.cookie_httponly', $value);
            $this->cli->writeln("  HttpOnly cookies: " . ($value ? 'yes' : 'no'));
        }
    }

    /**
     * Show current session configuration
     *
     * @param ConfigHelper $helper Configuration helper
     */
    private function showCurrentConfig(ConfigHelper $helper): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Current Session Handler Configuration');
        $this->cli->writeln('=====================================');
        $this->cli->writeln();

        $session = $helper->getValue('sessionhandler');

        if (empty($session)) {
            $this->cli->message('No session handler configuration found.', 'cli.warning');
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('Handler:');
        $this->cli->writeln('  Type:              ' . ($session['type'] ?? '(not set)'));

        if (isset($session['path'])) {
            $this->cli->writeln('  Path:              ' . $session['path']);
        }

        if (isset($session['memcache'])) {
            $this->cli->writeln('  Memcache servers:  ' . $this->formatMemcacheForDisplay($helper));
        }

        $this->cli->writeln();
        $this->cli->writeln('Timeout Settings:');
        $this->cli->writeln('  Timeout:           ' . ($session['timeout'] ?? '(default)') . ' seconds');
        $this->cli->writeln('  GC max lifetime:   ' . ($session['gc_maxlifetime'] ?? 1440) . ' seconds');
        $this->cli->writeln('  GC probability:    ' . ($session['gc_probability'] ?? 1));

        $this->cli->writeln();
        $this->cli->writeln('Cookie Settings:');
        $this->cli->writeln('  Use only cookies:  ' . $this->formatBoolean($session['use_only_cookies'] ?? true));
        $this->cli->writeln('  Secure cookies:    ' . $this->formatBoolean($session['cookie_secure'] ?? false));
        $this->cli->writeln('  HttpOnly cookies:  ' . $this->formatBoolean($session['cookie_httponly'] ?? true));

        $this->cli->writeln();
    }

    /**
     * Parse memcache server list
     *
     * @param ConfigHelper $helper Configuration helper
     * @param string $servers Comma-separated list of host:port
     */
    private function parseMemcacheServers(ConfigHelper $helper, string $servers): void
    {
        $serverList = [];
        $parts = array_map('trim', explode(',', $servers));

        foreach ($parts as $server) {
            if (empty($server)) {
                continue;
            }

            // Parse host:port
            if (strpos($server, ':') !== false) {
                list($host, $port) = explode(':', $server, 2);
                $serverList[] = [
                    'hostspec' => trim($host),
                    'port' => (int)trim($port)
                ];
            } else {
                $serverList[] = [
                    'hostspec' => trim($server),
                    'port' => 11211  // Default memcache port
                ];
            }
        }

        $helper->setValue('sessionhandler.memcache', $serverList);
    }

    /**
     * Format memcache servers for display
     *
     * @param ConfigHelper $helper Configuration helper
     * @return string Formatted server list
     */
    private function formatMemcacheForDisplay(ConfigHelper $helper): string
    {
        $memcache = $helper->getValue('sessionhandler.memcache');
        if (empty($memcache) || !is_array($memcache)) {
            return 'localhost:11211';
        }

        $servers = [];
        foreach ($memcache as $server) {
            if (is_array($server)) {
                $host = $server['hostspec'] ?? 'localhost';
                $port = $server['port'] ?? 11211;
                $servers[] = "{$host}:{$port}";
            }
        }

        return implode(', ', $servers) ?: 'localhost:11211';
    }
}
