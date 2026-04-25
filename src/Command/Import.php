<?php

namespace Horde\Hordectl\Command;

use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\HasModulesTrait;
use Horde\Hordectl\Output;
use Horde\Yaml\Yaml;
use Horde\Injector\Injector;
use Horde\Argv\Option;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;

/**
 *
 * Import command module implements CLI Query Yaml import
 */
class Import implements Module, ModuleUsage
{
    use ModuleTrait;
    use HasModulesTrait;

    protected HordeCli $cli;
    protected Output $output;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->_parser = $dependencies->getInstance(Parser::class);
        // We stop parsing after the first positional
        //        $this->_parser->allowInterspersedArgs = false;
        $this->_initModules(
            $dependencies,
            '\Horde\Hordectl\Command\Import',
            dirname(__FILE__) . '/Import'
        );
    }

    public function getBaseOptions(): array
    {
        return
            [
                new Option(
                    '-f',
                    '--filename',
                    [
                        'action' => 'store',
                        'type' => 'string',
                        'dest' => 'filename',
                        'help'   => 'The Yaml file to read',
                    ]
                ),
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

        $parser = new Parser();
        $parser->addOption(new Option('-f', '--filename', ['dest' => 'filename']));
        $parser->allowInterspersedArgs = false;

        [$myArgs, $moduleArgs] = $this->handleCommandline($argv);
        // identify yaml file or input stream
        // TODO: Handle "-" or console input redirects
        if (!$myArgs->filename) {
            $this->cli->writeln();
            $this->cli->writeln('Usage: hordectl import -f FILE');
            $this->cli->writeln();
            $this->cli->writeln('Import resources into Horde from a YAML file.');
            $this->cli->writeln();
            $this->cli->writeln('Options:');
            $this->cli->writeln('  -f, --filename FILE    YAML file to import (required)');
            $this->cli->writeln();
            return false;
        }
        if (!is_file($myArgs->filename)) {
            $this->output->error('File not found: ' . $myArgs->filename);
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
