<?php

namespace Horde\Hordectl\Command\Query;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use \Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
/**
 *
 * Query command module for Horde users
 */
class User
implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;
    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->parser = $dependencies->getInstance('\Horde_Argv_Parser');
        // We stop parsing after the first positional
        $this->parser->allowInterspersedArgs = false;
    }

    /**
     * Decide if this module handles the commandline
     * 
     * @params array $globalOpts  Commandline Options already parsed by previous levels
     * @params array $argv        The arguments for the parser to digest
     */
    public function handle(array $argv = [])
    {
        // Do not act on empty argv
        if (count($argv) < 1) {
            return false;
        }
        if ($argv[0] != 'user') {
            return false;
        }
        // TODO: accept some filters on which users to export and which details to export
        $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');

        $exporter = $this->dependencies->getInstance('UserRepo');
        $items = $exporter->export();
        $writer->addResource('builtin', 'user', $items);
        return true;
    }
}