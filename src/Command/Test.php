<?php

namespace Horde\Hordectl\Command;

use Horde\Argv\Option;
use Horde\Argv\Parser;
use Horde\Hordectl\HasModulesTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;

/**
 * Test command module implements CLI subsystem testing
 *
 * Performs diagnostic checks on Horde subsystems similar to base/test.php
 */
class Test implements Module, ModuleUsage
{
    use ModuleTrait;
    use HasModulesTrait;

    protected \Horde_Cli $cli;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->_parser = $dependencies->getInstance(Parser::class);
        // We stop parsing after the first positional
        $this->_parser->allowInterspersedArgs = false;
        $this->_initModules(
            $dependencies,
            '\Horde\Hordectl\Command\Test',
            dirname(__FILE__) . '/Test'
        );
    }

    /**
     * Decide if this module handles the commandline
     *
     * @params array $argv        The arguments for the parser to digest
     */
    public function handle(array $argv = [])
    {
        // Do not act on empty argv
        if (count($argv) < 1) {
            return false;
        }
        if ($argv[0] != 'test') {
            return false;
        }

        list($myArgs, $moduleArgs) = $this->handleCommandline($argv);

        // Show help if no subcommand provided
        if (empty($moduleArgs)) {
            $this->showHelp();
            return true;
        }

        // Run the subcommand tests - stop after first handler
        $res = false;
        foreach ($this->listModules() as $module) {
            $handled = $module->handle($moduleArgs);
            if ($handled) {
                $res = true;
                break;  // Stop after first module handles it
            }
        }

        // If no subcommand handled the request, show help
        if (!$res) {
            $this->cli->writeln();
            $this->cli->message(
                sprintf('Unknown test subsystem: %s', $moduleArgs[0]),
                'cli.error'
            );
            $this->showHelp();
        }

        return true;
    }

    /**
     * Display help information
     */
    protected function showHelp(): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Usage: hordectl test SUBSYSTEM');
        $this->cli->writeln();
        $this->cli->writeln('Test Horde subsystems and report configuration status.');
        $this->cli->writeln();
        $this->cli->writeln('Available subsystems:');
        $this->cli->writeln();
        $this->cli->writeln('  db             Test database connection and driver');
        $this->cli->writeln('  cache          Test cache system functionality');
        $this->cli->writeln('  session        Test session handler configuration');
        $this->cli->writeln('  logger         Test logging system');
        $this->cli->writeln('  auth           Test authentication system');
        $this->cli->writeln('  jwt            Test JWT authentication configuration');
        $this->cli->writeln('  all            Run all subsystem tests');
        $this->cli->writeln();
        $this->cli->writeln('Examples:');
        $this->cli->writeln('  hordectl test db              # Test database connection');
        $this->cli->writeln('  hordectl test auth            # Test authentication system');
        $this->cli->writeln('  hordectl test all             # Run all tests');
        $this->cli->writeln();
    }

    /**
     * Get detailed usage information
     *
     * @return string
     */
    public function getUsage()
    {
        return 'test SUBSYSTEM

Test Horde subsystems and report configuration status.

Available subsystems:
    db          Test database connection and driver
    cache       Test cache system functionality
    session     Test session handler configuration
    logger      Test logging system
    auth        Test authentication system
    jwt         Test JWT authentication configuration
    all         Run all subsystem tests

EXAMPLES
    # Test database connection
    hordectl test db

    # Test authentication system
    hordectl test auth

    # Run all tests
    hordectl test all

Each test reports configuration details and verifies that the subsystem
is working correctly. Tests use reflection to introspect wrapped drivers
(e.g., Horde_Core_Auth_Application wrapping Horde_Auth_Sql).
';
    }

    /**
     * Get short summary for command list
     *
     * @return string
     */
    public function getSummary()
    {
        return 'Test Horde subsystems (db, cache, session, logger, auth, jwt)';
    }
}
