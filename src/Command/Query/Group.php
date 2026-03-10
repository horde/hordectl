<?php

namespace Horde\Hordectl\Command\Query;
use \Horde\Hordectl\Resource\GroupResource;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use \Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
/**
 *
 * Query command module for Horde Group
 */
class Group
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

    /**
     * Decide if this module handles the commandline
     *
     * Usage:
     *   hordectl query group              - Export all groups
     *   hordectl query group <groupname>  - Export specific group
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

        $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');
        unset($GLOBALS['conf']);
        $exporter = $this->dependencies->getInstance('GroupRepo');

        // Check if a specific group name was provided
        if (isset($argv[1]) && !empty($argv[1])) {
            $groupname = $argv[1];

            // Export all groups and filter
            $allItems = $exporter->export();
            $items = array_filter($allItems, function($item) use ($groupname) {
                return isset($item['groupName']) && $item['groupName'] === $groupname;
            });

            if (empty($items)) {
                $this->cli->message(
                    sprintf('Group "%s" not found', $groupname),
                    'cli.error'
                );
                return true;
            }

            $writer->addResource('builtin', 'group', array_values($items));
        } else {
            // Export all groups
            $items = $exporter->export();
            $writer->addResource('builtin', 'group', $items);
        }

        return true;
    }
}