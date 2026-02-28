<?php

namespace Horde\Hordectl\Command;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use \Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
/**
 *
 * Help command module implements CLI help/usage
 */
class Help
implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;
    protected \Horde_Argv_Parser $parser;

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
     * @params array $argv        The arguments for the parser to digest
     */
    public function handle(array $argv = [])
    {
        // Do not act on empty argv
        if (count($argv) < 1) {
            return false;
        }
        if ($argv[0] == 'help') {
            // TODO: Identify modules. If no module argument is given or module does not exist,
            // print global help. Otherwise print module-specific help
            $this->cli->writeln('Help');
            $this->cli->writeln('Found Horde at: ' . $this->dependencies->findHordePath());
            $registry = $this->dependencies->getInstance('HordeRegistry');
            foreach ($this->dependencies->getRegistryApplications() as $app)
            {
                $status = $registry->applications[$app]['status'];

                $this->cli->writeln(
                    sprintf(
                        'Found Application: %12s (%s)',
                        $app,
                        $status
                    )
                );
                $resources = $this->dependencies->getApplicationResources($app);
                if ($resources) {
                    foreach ($resources as $resource) {
                        if ($resource) {
                            $this->cli->writeln('Has Resources');
                        }
                    }
                }
            }
            return true;
        }
        return false;
    }
}