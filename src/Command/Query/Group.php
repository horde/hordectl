<?php

namespace Horde\Hordectl\Command\Query;
use \Horde\Hordectl\Resource\GroupResource;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use \Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
/**
 *
 * Query command module for Horde Group
 */
class Group
implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;
    public function __construct(\Horde_Injector $dependencies)
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
        if ($argv[0] != 'group') {
            return false;
        }
        // TODO: accept some filters on which groups to export and which details to export
        $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');
        unset($GLOBALS['conf']);
        $exporter = $this->dependencies->getInstance('GroupRepo');
        $items = $exporter->export();
        $writer->addResource('builtin', 'group', $items);
        return true;
    }
}