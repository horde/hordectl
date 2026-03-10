<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Argv\Parser;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;

/**
 * Test session handler configuration
 */
class Session implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->parser = $dependencies->getInstance(Parser::class);
        $this->parser->allowInterspersedArgs = false;
    }

    /**
     * Handle the test command
     *
     * @param array $argv
     * @return bool
     */
    public function handle(array $argv = [])
    {
        if (count($argv) < 1) {
            return false;
        }
        if ($argv[0] != 'session') {
            return false;
        }

        $this->cli->writeln();
        $this->cli->writeln('Session Handler Test');
        $this->cli->writeln('====================');
        $this->cli->writeln();

        try {
            $conf = $GLOBALS['conf'] ?? null;
            $sessionConfigured = false;
            $configuredType = 'not configured';
            $configuredHashtable = null;

            // Check session handler configuration
            if (isset($conf['sessionhandler']['type'])) {
                $configuredType = $conf['sessionhandler']['type'];
                $sessionConfigured = true;
                if (isset($conf['sessionhandler']['hashtable'])) {
                    $configuredHashtable = $conf['sessionhandler']['hashtable'];
                }
            }

            $this->cli->message('Configured type: ' . $configuredType, 'cli.message');
            if ($configuredHashtable !== null) {
                $this->cli->message('Hashtable mode: ' . ($configuredHashtable ? 'yes' : 'no'), 'cli.message');
            }

            if (!isset($GLOBALS['session'])) {
                $this->cli->message('Session not available in global scope (CLI mode)', 'cli.warning');
                return true;
            }

            $session = $GLOBALS['session'];
            if (!$session || !$session->sessionHandler) {
                $this->cli->message('Session handler not initialized (CLI mode)', 'cli.warning');
                return true;
            }

            $actualHandler = get_class($session->sessionHandler);
            $this->cli->message('Active handler class: ' . $actualHandler, 'cli.message');

            // Check if this is Horde_SessionHandler wrapper and introspect the storage backend
            if ($actualHandler === 'Horde_SessionHandler') {
                try {
                    $reflection = new \ReflectionClass($session->sessionHandler);
                    if ($reflection->hasProperty('_storage')) {
                        $storageProperty = $reflection->getProperty('_storage');
                        $storageProperty->setAccessible(true);
                        $storageBackend = $storageProperty->getValue($session->sessionHandler);

                        if ($storageBackend !== null) {
                            $storageClass = get_class($storageBackend);
                            $this->cli->message('Storage backend: ' . $storageClass, 'cli.message');
                        }
                    }
                } catch (\ReflectionException $e) {
                    // Reflection failed
                }
            }

            // Check cookie configuration
            if (isset($conf['cookie']['domain'])) {
                $cookieDomain = $conf['cookie']['domain'];
                $serverName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'unknown';

                $this->cli->message('Cookie domain: ' . ($cookieDomain ?: '(empty)'), 'cli.message');

                // Warn if server name has no dots but cookie domain is set
                if (strpos($serverName, '.') === false && $cookieDomain !== '') {
                    $this->cli->message(
                        'WARNING: Server name "' . $serverName . '" has no dots but cookie domain is set. Sessions may not work.',
                        'cli.warning'
                    );
                    $this->cli->message('Consider setting: $conf[\'cookie\'][\'domain\'] = \'\';', 'cli.message');
                }
            }

            $this->cli->writeln();
            $this->cli->message('Session handler: OK', 'cli.success');
        } catch (\Exception $e) {
            $this->cli->message('Error: ' . $e->getMessage(), 'cli.error');
        }

        $this->cli->writeln();
        return true;
    }
}
