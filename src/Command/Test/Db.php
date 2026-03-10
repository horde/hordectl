<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Argv\Parser;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;

/**
 * Test database connection and driver
 */
class Db implements Module, ModuleUsage
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
        if ($argv[0] != 'db') {
            return false;
        }

        $this->cli->writeln();
        $this->cli->writeln('Database Connection Test');
        $this->cli->writeln('========================');
        $this->cli->writeln();

        try {
            $conf = $GLOBALS['conf'] ?? null;
            if (!isset($conf['sql']['phptype'])) {
                $this->cli->message('Database not configured in conf.php', 'cli.warning');
                return true;
            }

            $dbType = $conf['sql']['phptype'];
            if ($dbType === false || $dbType === 'false' || $dbType === '') {
                $this->cli->message('Database disabled (phptype = ' . var_export($dbType, true) . ')', 'cli.warning');
                return true;
            }

            $this->cli->message('Configured type: ' . $dbType, 'cli.message');

            // Check PHP extension
            $extensionMap = [
                'mysql' => 'mysql',
                'mysqli' => 'mysqli',
                'pdo_mysql' => 'pdo_mysql',
                'pgsql' => 'pgsql',
                'pdo_pgsql' => 'pdo_pgsql',
                'sqlite' => 'pdo_sqlite',
                'pdo_sqlite' => 'pdo_sqlite',
                'oci8' => 'oci8',
                'pdo_oci' => 'pdo_oci',
            ];
            $phpExtension = $extensionMap[$dbType] ?? $dbType;
            $extensionLoaded = extension_loaded($phpExtension);

            if ($extensionLoaded) {
                $this->cli->message('PHP extension: ' . $phpExtension . ' (loaded)', 'cli.message');
            } else {
                $this->cli->message('PHP extension: ' . $phpExtension . ' (NOT LOADED)', 'cli.error');
                return true;
            }

            if (isset($GLOBALS['injector'])) {
                $db = $GLOBALS['injector']->getInstance('Horde_Db_Adapter');
                if ($db) {
                    $dbDriver = get_class($db);
                    $this->cli->message('Driver class: ' . $dbDriver, 'cli.message');

                    // Try a simple query
                    try {
                        $result = $db->selectValue('SELECT 1');
                        if ($result == 1) {
                            $this->cli->writeln();
                            $this->cli->message('Database connection: OK', 'cli.success');
                        } else {
                            $this->cli->message('Database query returned unexpected result: ' . var_export($result, true), 'cli.error');
                        }
                    } catch (\Exception $e) {
                        $this->cli->message('Database query failed: ' . $e->getMessage(), 'cli.error');
                    }
                } else {
                    $this->cli->message('Database adapter not initialized', 'cli.error');
                }
            } else {
                $this->cli->message('Injector not available', 'cli.error');
            }
        } catch (\Exception $e) {
            $this->cli->message('Error: ' . $e->getMessage(), 'cli.error');
        }

        $this->cli->writeln();
        return true;
    }
}
