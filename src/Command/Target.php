<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command;

use Horde\Argv\Parser;
use Horde\Hordectl\HasModulesTrait;
use Horde\Hordectl\HordectlModuleTrait;
use Horde\Hordectl\Output;
use Horde\Injector\Injector;
use Horde\Cli\Cli as HordeCli;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;

/**
 * Target command module
 *
 * Parent module for target management commands. Delegates to subcommands:
 * - current: Show current target
 * - list: List all targets
 * - use: Switch to a target
 * - add: Create new target
 * - update: Update existing target
 * - rename: Rename a target
 * - delete: Delete a target
 * - show: Show target details
 * - test: Test target connectivity
 * - sync-secret: Sync API secret from conf.php
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Target implements Module, ModuleUsage
{
    use HordectlModuleTrait;
    use HasModulesTrait;

    protected HordeCli $cli;
    protected Output $output;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->_parser = $dependencies->getInstance(Parser::class);
        $this->_parser->allowInterspersedArgs = false;

        $this->_initModules(
            $dependencies,
            '\Horde\Hordectl\Command\Target',
            dirname(__FILE__) . '/Target'
        );
    }

    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1) {
            return false;
        }

        if ($argv[0] !== 'target') {
            return false;
        }

        [$myArgs, $moduleArgs] = $this->handleCommandline($argv);

        // Show help if no subcommand provided
        if (empty($moduleArgs)) {
            $this->showHelp();
            return true;
        }

        // Delegate to submodules
        foreach ($this->listModules() as $module) {
            if ($module->handle($moduleArgs)) {
                return true;
            }
        }

        // No subcommand matched
        $this->cli->writeln($this->cli->red("Unknown target subcommand: {$moduleArgs[0]}"));
        $this->showHelp();
        return true;
    }

    public function getUsage(): string
    {
        return 'target <subcommand> [options]';
    }

    public function getHelp(): string
    {
        return <<<'HELP'
            Manage hordectl targets (Horde installations).

            Targets represent local or remote Horde installations:
            - Local targets: Have filesystem access for configuration and management
            - Remote targets: Have REST API access only for queries and operations

            SUBCOMMANDS:
              current      Show current active target
              list         List all configured targets
              use          Switch to a different target
              add          Add a new target
              update       Update an existing target
              rename       Rename a target
              delete       Delete a target
              show         Show detailed target information
              test         Test target connectivity
              sync-secret  Sync API credentials from conf.php (local targets only)

            EXAMPLES:
              hordectl target current
              hordectl target list
              hordectl target use staging
              hordectl target add mydev --type=local --path=/var/www/horde
              hordectl target add prod --type=remote --endpoint=https://prod.example.com/horde --secret=abc123

            For help on a specific subcommand:
              hordectl target <subcommand> --help
            HELP;
    }

    protected function showHelp(): void
    {
        $this->cli->writeln();
        $this->cli->writeln("Usage: hordectl " . $this->getUsage());
        $this->cli->writeln();
        $this->cli->writeln($this->getHelp());
        $this->cli->writeln();
    }
}
