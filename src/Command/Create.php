<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

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
 * Create command module
 *
 * Parent module for resource creation commands. Delegates to subcommands:
 * - user: Create a user account
 * - group: Create a group
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Create implements Module, ModuleUsage
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
            '\Horde\Hordectl\Command\Create',
            dirname(__FILE__) . '/Create'
        );
    }

    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1) {
            return false;
        }

        if ($argv[0] !== 'create') {
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
        $this->cli->writeln($this->cli->red("Unknown resource type: {$moduleArgs[0]}"));
        $this->showHelp();
        return true;
    }

    public function getUsage(): string
    {
        return 'create <resource-type> [options]';
    }

    public function getTitle(): string
    {
        return 'create';
    }

    protected function showHelp(): void
    {
        $this->cli->writeln();
        $this->cli->writeln("Usage: hordectl " . $this->getUsage());
        $this->cli->writeln();
        $this->cli->writeln("Create Horde resources interactively or via command-line options.");
        $this->cli->writeln();
        $this->cli->writeln("Available resource types:");
        $this->cli->writeln("  user       Create a user account");
        $this->cli->writeln("  group      Create a group");
        $this->cli->writeln();
        $this->cli->writeln("Interactive mode:");
        $this->cli->writeln("  Prompts for missing required fields when running from a TTY.");
        $this->cli->writeln();
        $this->cli->writeln("Non-interactive mode:");
        $this->cli->writeln("  Provide all required fields via CLI flags.");
        $this->cli->writeln("  Missing required fields will cause an error.");
        $this->cli->writeln();
        $this->cli->writeln("Examples:");
        $this->cli->writeln("  hordectl create user");
        $this->cli->writeln("  hordectl create user --username admin --password secret");
        $this->cli->writeln("  hordectl create group --name admins --members alice,bob");
        $this->cli->writeln();
        $this->cli->writeln("For resource-specific help:");
        $this->cli->writeln("  hordectl create user --help");
        $this->cli->writeln("  hordectl create group --help");
        $this->cli->writeln();
    }
}
