<?php

namespace Horde\Hordectl\Command;

use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\HasModulesTrait;
use Horde\Hordectl\Output;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;

/**
 *
 * Query command module implements CLI Query Yaml output
 */
class Query implements Module, ModuleUsage
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

        [$myArgs, $moduleArgs] = $this->handleCommandline($argv);

        // Show help if no subcommand provided
        if (empty($moduleArgs)) {
            $this->showHelp();
            return true;
        }

        $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');
        $res = false;
        foreach ($this->listModules() as $module) {
            $res |= $module->handle($moduleArgs);
        }

        // If no subcommand handled the request, show help
        if (!$res) {
            $this->cli->writeln();
            $this->output->error(
                sprintf('Unknown query resource type: %s', $moduleArgs[0])
            );
            $this->showHelp();
            return true;
        }

        $this->cli->writeln($writer->dump());
        return true;
    }

    /**
     * Display help information
     */
    protected function showHelp(): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Usage: hordectl query RESOURCE [IDENTIFIER]');
        $this->cli->writeln();
        $this->cli->writeln('Query and export Horde resources as YAML format.');
        $this->cli->writeln();
        $this->cli->writeln($this->cli->yellow('Note: Query commands require a target with API endpoint configured.'));
        $this->cli->writeln('      Local targets need --endpoint, or switch to a remote target.');
        $this->cli->writeln();
        $this->cli->writeln('Available resource types:');
        $this->cli->writeln();
        $this->cli->writeln('  apps                  Query applications (meta-resources via REST API)');
        $this->cli->writeln('                        - Lists all registered applications');
        $this->cli->writeln('                        - Applications define resource types (like Kubernetes CRDs)');
        $this->cli->writeln();
        $this->cli->writeln('  user [username]       Query user accounts and identities');
        $this->cli->writeln('                        - Without username: export all users');
        $this->cli->writeln('                        - With username: export specific user');
        $this->cli->writeln();
        $this->cli->writeln('  group [groupname]     Query user groups and memberships');
        $this->cli->writeln('                        - Without groupname: export all groups');
        $this->cli->writeln('                        - With groupname: export specific group');
        $this->cli->writeln();
        $this->cli->writeln('  permission [name]     Query permissions');
        $this->cli->writeln('                        - Without name: export all permissions');
        $this->cli->writeln('                        - With name: export specific permission');
        $this->cli->writeln();
        $this->cli->writeln('  {app}/{resource}      Query app-specific resources (via legacy bootstrap)');
        $this->cli->writeln('                        - Example: turba/contacts, kronolith/events');
        $this->cli->writeln('                        - Requires Horde bootstrap and app ApplicationResources class');
        $this->cli->writeln();
        $this->cli->writeln('Examples:');
        $this->cli->writeln('  hordectl query apps                    # List applications via REST API');
        $this->cli->writeln('  hordectl query user                    # Export all users');
        $this->cli->writeln('  hordectl query user administrator      # Export specific user');
        $this->cli->writeln('  hordectl query group                   # Export all groups');
        $this->cli->writeln('  hordectl query turba/contacts          # Export Turba contacts');
        $this->cli->writeln();
        $this->cli->writeln('Output is in YAML format, suitable for use with "hordectl import".');
        $this->cli->writeln();
    }

    /**
     * Get detailed usage information
     *
     * @return string
     */
    public function getUsage(): string
    {
        return 'query RESOURCE [IDENTIFIER]

Query and export Horde resources as YAML format.

Available resource types:
    user [username]       Query user accounts and identities
    group [groupname]     Query user groups and memberships
    app [appname]         Query application configuration
    permission [name]     Query permissions

EXAMPLES
    # Export all users
    hordectl query user

    # Export specific user
    hordectl query user administrator

    # Export all groups
    hordectl query group

    # Export specific group
    hordectl query group admins

Output is in YAML format, suitable for use with "hordectl import".
';
    }

    /**
     * Get short summary for command list
     *
     * @return string
     */
    public function getSummary(): string
    {
        return 'Query and export Horde resources via API (requires API endpoint)';
    }
}
