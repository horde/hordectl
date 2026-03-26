<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\HasModulesTrait;
use Horde\Hordectl\Output;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;

/**
 * Configure command module - setup and configure Horde subsystems
 *
 * This command provides subcommands for configuring various Horde subsystems
 * like database, authentication, preferences, etc.
 *
 * @author Ralf Lang <ralf.lang@ralf-lang.de>
 */
class Configure implements Module, ModuleUsage
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
            '\Horde\Hordectl\Command\Configure',
            dirname(__FILE__) . '/Configure',
            ['ConfigureHelperTrait']
        );
    }

    public function getBaseOptions()
    {
        return [];
    }

    /**
     * Decide if this module handles the commandline
     *
     * @param array $argv The arguments for the parser to digest
     * @return bool True if command was handled
     */
    public function handle(array $argv = []): bool
    {
        // Do not act on empty argv
        if (count($argv) < 1) {
            return false;
        }

        if ($argv[0] !== 'configure' && $argv[0] !== 'config') {
            return false;
        }

        // Remove 'configure' from argv
        array_shift($argv);

        // If no subcommand, show usage
        if (empty($argv)) {
            $this->showUsage();
            return true;
        }

        // Try to delegate to submodules
        $handled = false;
        [$myArgs, $moduleArgs] = $this->handleCommandline($argv);
        foreach ($this->listModules() as $module) {
            $handled |= $module->handle($moduleArgs);
        }

        // If no submodule handled it, show usage
        if (!$handled) {
            $this->cli->writeln();
            $this->output->error("Unknown configure subcommand: {$argv[0]}");
            $this->cli->writeln();
            $this->showUsage();
        }

        return true;
    }

    /**
     * Show usage information for the configure command
     */
    protected function showUsage(): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Usage: hordectl configure SUBCOMMAND [OPTIONS]');
        $this->cli->writeln('       hordectl config SUBCOMMAND [OPTIONS]');
        $this->cli->writeln();
        $this->cli->writeln('Configure Horde subsystems and settings.');
        $this->cli->writeln();
        $this->cli->writeln($this->cli->yellow('Note: Configure commands require a local target with filesystem access.'));
        $this->cli->writeln('      Remote targets cannot be configured via hordectl.');
        $this->cli->writeln();
        $this->cli->writeln('Available subcommands:');

        $subcommands = $this->listModules();
        if (empty($subcommands)) {
            $this->cli->writeln('  (No subcommands available yet)');
        } else {
            foreach ($subcommands as $class => $module) {
                $name = strtolower(basename(str_replace('\\', '/', $class)));
                $description = $this->getSubcommandDescription($name);
                $this->cli->writeln('  ' . str_pad($name, 15) . ' ' . $description);
            }
        }

        $this->cli->writeln();
        $this->cli->writeln('Run \'hordectl configure SUBCOMMAND --help\' for more information on a subcommand.');
        $this->cli->writeln();
    }

    /**
     * Get description for a subcommand
     *
     * @param string $name Subcommand name
     * @return string Description
     */
    protected function getSubcommandDescription(string $name): string
    {
        $descriptions = [
            'database' => 'Configure database connection settings',
            'auth' => 'Configure authentication backend',
            'mailer' => 'Configure mail transport settings',
            'prefs' => 'Configure preferences backend',
            'cache' => 'Configure caching backend',
            'sessionhandler' => 'Configure session handler and storage',
            'ldap' => 'Configure LDAP connection and settings',
            'groups' => 'Configure groups backend and management',
            'permissions' => 'Configure permissions system',
            'tokens' => 'Configure authentication tokens',
        ];

        return $descriptions[$name] ?? '';
    }
}
