<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Argv\Parser;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;

/**
 * Run all subsystem tests
 */
class All implements Module, ModuleUsage
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
        if ($argv[0] != 'all') {
            return false;
        }

        $this->cli->writeln();
        $this->cli->writeln('========================================');
        $this->cli->writeln('Horde Subsystem Tests');
        $this->cli->writeln('========================================');

        // Run all tests by calling each test with its specific name (not 'all')
        $tests = ['db', 'cache', 'session', 'logger', 'auth', 'jwt'];

        foreach ($tests as $test) {
            // Create test instance and run it with its specific test name
            $className = 'Horde\\Hordectl\\Command\\Test\\' . ucfirst($test);
            if (class_exists($className)) {
                $testInstance = new $className($this->dependencies);
                $testInstance->handle([$test]);  // Pass the specific test name, not 'all'
            }
        }

        $this->cli->writeln('========================================');
        $this->cli->writeln('All subsystem tests complete');
        $this->cli->writeln('========================================');
        $this->cli->writeln();

        return true;
    }
}
