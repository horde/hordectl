<?php

namespace Horde\Hordectl\Command\Import;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use \Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
/**
 *
 * Import command module for Horde permissions
 */
class Permission
implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;
    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->parser = $dependencies->getInstance(Parser::class);
        // We stop parsing after the first positional
        $this->parser->allowInterspersedArgs = false;
    }

    public function import(string $app, string $resource, array $tree)
    {
        if ($app != 'builtin' || $resource != 'permission') {
            return false;
        }
        $items = $tree['apps']['builtin']['resources']['permission']['items'];
        // initialize PermissionImporter, mind any commandline or tree meta
        $importer = $this->dependencies->getInstance('PermsRepo');
        foreach ($items as $item) {
            $importer->import($item);
        }
        return true;
    }
}