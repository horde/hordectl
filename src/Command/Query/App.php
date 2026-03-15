<?php

namespace Horde\Hordectl\Command\Query;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Horde_Cli;

/**
 *
 * Query command module for resources implemented by Horde Registry Apps
 */
class App implements Module, ModuleUsage
{
    use ModuleTrait;

    protected Horde_Cli $cli;
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
     * @params array $globalOpts  Commandline Options already parsed by previous levels
     * @params array $argv        The arguments for the parser to digest
     */
    public function handle(array $argv = [])
    {
        // Do not act on empty argv
        if (count($argv) < 1) {
            return false;
        }
        // Break out to apps to decide if we handle this.
        $apps = $this->dependencies->getRegistryApplications();
        $parts = explode('/', $argv[0], 2);
        if (count($parts) < 2) {
            // Not in app/resource format
            return false;
        }
        [$app, $resource] = $parts;
        // Is that app registered?
        if (!in_array($app, $apps)) {
            return false;
        }
        $reader = $this->dependencies->getInstance('AppConfigReader');
        $config =  $reader->getAppConfig($app);
        $GLOBALS['conf'] = $config;
        $api = $this->dependencies->getApplicationResources($app);
        if (!$api) {
            return false;
        }
        $resources = $api->getTypeList();
        // Is that resource defined?
        if (!in_array($resource, $resources)) {
            return false;
        }
        // Does the app handle the query command?
        if (!method_exists($api, 'queryType')) {
            return false;
        }
        // Instanciate app context if necessary
        // Things outside PSR-4 compatible namespaces may be a bit brittle
        $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');
        $response = $api->queryType($resource);
        // Bail out if the resource does not implement queries
        if (empty($response)) {
            return false;
        }
        $writer->addResource($app, $resource, $response['items']);
        return true;
    }
}
