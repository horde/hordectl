<?php

namespace Horde\Hordectl\Command\Import;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde\Argv\Parser;

/**
 *
 * Import command module for Horde App provided resources
 */
class App implements Module, ModuleUsage
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
        // This is needed if the import of this resource requires calls to a Horde_Registry_Api class
        $this->dependencies->getInstance('Horde_Registry')->setAuthenticationSetting('none');
        // Break out to apps to decide if we handle this.
        $apps = $this->dependencies->getRegistryApplications();
        if ($app == 'builtin') {
            // Not for this module
            return false;
        }

        // Is that app registered?
        if (!in_array($app, $apps)) {
            $this->cli->writeln("App $app is not registered.");
            return false;
        }
        try {
            $api = $this->dependencies->getApplicationResources($app);
            $resources = $api->getTypeList();
        } catch (\Exception $e) {
            $this->cli->writeln($e->getMessage());
            $this->cli->writeln("Not importing $resource");
        }
        // Is that resource defined?
        if (!in_array($resource, $resources)) {
            $this->cli->writeln("Resource $resource not defined in $app");
            return false;
        }
        // Does the app handle the query command?
        if (!method_exists($api, 'importType')) {
            $this->cli->writeln("query not defined in $app");
            return false;
        }
        $items = $tree['apps'][$app]['resources'][$resource]['items'];
        try {
            $response = $api->importType($resource, $items);
        } catch (\Exception $e) {
            $this->cli->writeln("Resource $resource not imported to $app");
            $this->cli->writeln($e->getMessage());
            return false;
        }
        $this->cli->message("Resource $resource imported to $app", 'cli.success');
        return true;
    }
}
