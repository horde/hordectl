<?php

namespace Horde\Hordectl\Command;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\HasModulesTrait;
use Horde\Yaml\Yaml;

/**
 *
 * Import command module implements CLI Query Yaml import
 */
class Import implements Module, ModuleUsage
{
    use ModuleTrait;
    use HasModulesTrait;
    public function __construct(\Horde_Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->_parser = $dependencies->getInstance('\Horde_Argv_Parser');
        // We stop parsing after the first positional
        //        $this->_parser->allowInterspersedArgs = false;
        $this->_initModules(
            $dependencies,
            '\Horde\Hordectl\Command\Import',
            dirname(__FILE__) . '/Import'
        );
    }

    public function getBaseOptions()
    {
        return
            [
                new \Horde_Argv_Option(
                    '-f',
                    '--filename',
                    [
                        'action' => 'store',
                        'type' => 'string',
                        'dest' => 'filename',
                        'help'   => 'The Yaml file to read'
                    ]
                )
            ];
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
        if ($argv[0] != 'import') {
            return false;
        }

        $parser = new \Horde_Argv_Parser();
        $parser->addOption(new \Horde_Argv_Option('-f', '--filename', ['dest' => 'filename']));
        $parser->allowInterspersedArgs = false;

        list($myArgs, $moduleArgs) = $this->handleCommandline($argv);
        // identify yaml file or input stream
        // TODO: Handle "-" or console input redirects
        if (!$myArgs->filename) {
            $this->cli->message('No Module ran', 'cli.error');
            return false;
        }
        if (!is_file($myArgs->filename)) {
            $this->cli->message('File not found: ' . $myArgs->filename, 'cli.error');
        }
        // Decode yaml
        $importData = Yaml::loadFile($myArgs->filename);
        // Find module for each resource type. Ignore unknown types
        foreach (array_keys($importData['apps']) as $app) {
            foreach (array_keys($importData['apps'][$app]['resources']) as $resource) {
                foreach ($this->listModules() as $module) {
                    $res = $module->import($app, $resource, $importData);
                }
            }
        }
        return true;
    }
}
