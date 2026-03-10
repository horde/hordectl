<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Argv\Parser;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;

/**
 * Test cache system functionality
 */
class Cache implements Module, ModuleUsage
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
        if ($argv[0] != 'cache') {
            return false;
        }

        $this->cli->writeln();
        $this->cli->writeln('Cache System Test');
        $this->cli->writeln('=================');
        $this->cli->writeln();

        try {
            if (!isset($GLOBALS['injector'])) {
                $this->cli->message('Injector not available', 'cli.error');
                return true;
            }

            $cache = $GLOBALS['injector']->getInstance('Horde_Cache');
            if (!$cache) {
                $this->cli->message('Cache not initialized', 'cli.error');
                return true;
            }

            $cacheDriver = get_class($cache);
            $this->cli->message('Cache driver: ' . $cacheDriver, 'cli.message');

            // Try to set and get a test value
            $testKey = 'hordectl_test_' . time();
            $testValue = 'test_value_' . mt_rand();

            $this->cli->message('Testing cache write...', 'cli.message');
            $cache->set($testKey, $testValue, 60);

            $this->cli->message('Testing cache read...', 'cli.message');
            $retrieved = $cache->get($testKey, 60);

            if ($retrieved === $testValue) {
                $cache->expire($testKey);
                $this->cli->writeln();
                $this->cli->message('Cache system: OK', 'cli.success');
            } else {
                $this->cli->message('Cache test failed - value mismatch', 'cli.error');
                $this->cli->message('Expected: ' . $testValue, 'cli.message');
                $this->cli->message('Got: ' . var_export($retrieved, true), 'cli.message');
            }
        } catch (\Exception $e) {
            $this->cli->message('Error: ' . $e->getMessage(), 'cli.error');
        }

        $this->cli->writeln();
        return true;
    }
}
