<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command;

use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\HasModulesTrait as HasModules;
use Horde\Hordectl\Output;
use Horde\Injector\Injector;
use Horde\Cli\Cli as HordeCli;

/**
 * Secret command - manage admin_secret for hordectl REST API authentication
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Hordectl
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Secret implements Module, ModuleUsage
{
    use ModuleTrait;
    use HasModules;

    protected HordeCli $cli;
    protected Output $output;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->_initModules(
            $dependencies,
            '\Horde\Hordectl\Command\Secret',
            dirname(__FILE__) . '/Secret'
        );
    }

    /**
     * Decide if this module handles the commandline
     *
     * @param array $argv The arguments for the parser to digest
     * @return bool
     */
    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1) {
            return false;
        }

        $command = $argv[0];
        if ($command === 'secret') {
            // Show help if no subcommand provided
            if (count($argv) < 2) {
                $this->showHelp();
                return true;
            }

            // Pass remaining args to submodules
            $moduleArgs = array_slice($argv, 1);
            $handled = false;
            foreach ($this->listModules() as $module) {
                if ($module->handle($moduleArgs)) {
                    $handled = true;
                    break;
                }
            }

            // If no subcommand handled it, show error and help
            if (!$handled) {
                $this->cli->writeln();
                $this->output->error(
                    sprintf('Unknown secret subcommand: %s', $moduleArgs[0])
                );
                $this->showHelp();
            }

            return true;
        }

        return false;
    }

    /**
     * Show help for secret command
     */
    protected function showHelp(): void
    {
        $this->cli->writeln();
        $this->output->ok('Hordectl Secret Management');
        $this->cli->writeln();
        $this->cli->writeln('Manage admin_secret for hordectl REST API authentication.');
        $this->cli->writeln();
        $this->cli->writeln('Available subcommands:');
        $this->cli->writeln('  secret generate    Generate new admin_secret (use --force to rotate)');
        $this->cli->writeln('  secret show        Display current admin_secret');
        $this->cli->writeln();
        $this->cli->writeln('Options:');
        $this->cli->writeln('  --force                         Replace existing secret (for rotation)');
        $this->cli->writeln('  --installation-root-dir=PATH    Path to Horde installation root (auto-detected)');
        $this->cli->writeln();
        $this->cli->writeln('Examples:');
        $this->cli->writeln('  hordectl secret generate                    # First-time setup');
        $this->cli->writeln('  hordectl secret generate --force            # Rotate secret');
        $this->cli->writeln('  hordectl secret show                        # View current secret');
        $this->cli->writeln();
    }

    /**
     * Get module usage information
     *
     * @return string
     */
    public function getUsage(): string
    {
        return 'Manage admin_secret for hordectl REST API authentication';
    }

    /**
     * Get module title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'secret';
    }

    /**
     * Get module description
     *
     * @return array
     */
    public function getUsageDescription(): array
    {
        return [
            'Manage admin_secret for hordectl REST API authentication',
            '',
            'Available subcommands:',
            '  secret generate    Generate new secret (use --force to rotate existing)',
            '  secret show        Display current admin_secret',
            '',
            'Usage:',
            '  hordectl secret generate [--force] [--installation-root-dir=/path]',
            '  hordectl secret show [--installation-root-dir=/path]',
        ];
    }
}
