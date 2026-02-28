<?php

namespace Horde\Hordectl\Command;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use \Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use \Horde\Hordectl\HasModulesTrait;
use Horde\Injector\Injector;
/**
 *
 * Query command module implements CLI Query Yaml output
 */
class Query
implements Module, ModuleUsage
{
    use ModuleTrait;
    use HasModulesTrait;

    protected \Horde_Cli $cli;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->_parser = $dependencies->getInstance('\Horde_Argv_Parser');
        // We stop parsing after the first positional
        $this->_parser->allowInterspersedArgs = false;
        $this->_initModules(
            $dependencies,
            '\Horde\Hordectl\Command\Query',
            dirname(__FILE__) . '/Query'
        );
    }

    /**
     * Decide if this module handles the commandline
     * 
     * Each query submodule returns an array.
     * Modules not queried return an empty array.
     * Modules queried return an array of format:
     * 
     * [apps]
     *   [$app] => The application providing the query module or "builtin"
     *     [resources] => A List of ResourceTypes
     *       [$resourceType] => The type identifier
     *          [items] => A List of resource entry representations
     * 
     * These will be merged and written to Yaml output format
     * 
     * @params array $argv        The arguments for the parser to digest
     */
    public function handle(array $argv = [])
    {
        // Do not act on empty argv
        if (count($argv) < 1) {
            return false;
        }
        if ($argv[0] != 'query') {
            return false;
        }
        $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');
        list($myArgs, $moduleArgs) = $this->handleCommandline($argv);
        foreach ($this->listModules() as $module) {
            $res |= $module->handle($moduleArgs);
        }
        $this->cli->writeln($writer->dump());
        return $res;
    }
}