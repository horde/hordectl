<?php

namespace Horde\Hordectl\Command\Import;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Horde_Cli;

/**
 * Import command module for Horde App provided resources
 *
 * UNSUPPORTED: This command requires app-specific CRD (Create/Read/Delete) support
 * via the Admin REST API, which is not yet implemented. App resources like
 * turba/contacts, kronolith/events, etc. are not available for import until
 * the REST API supports app-specific resource operations.
 *
 * Status: DEFERRED until REST API CRD support is finished
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

    public function import(string $app, string $resource, array $tree)
    {
        // UNSUPPORTED: App-specific resources not available until REST API CRD support
        if ($app == 'builtin') {
            // Not for this module - builtin resources handled by other importers
            return false;
        }

        // App-specific resources (turba/contacts, kronolith/events, etc.)
        // require REST API CRD operations which are not yet implemented
        $this->cli->message(
            "UNSUPPORTED: App resource import for '$app/$resource' requires REST API CRD support.",
            'cli.warning'
        );
        $this->cli->message(
            'App-specific resource import is deferred until Admin REST API CRD operations are implemented.',
            'cli.warning'
        );

        return false;
    }
}
