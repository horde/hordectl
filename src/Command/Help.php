<?php

namespace Horde\Hordectl\Command;

use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Exception;
use Horde\Cli\Cli as HordeCli;

/**
 *
 * Help command module implements CLI help/usage
 */
class Help implements Module, ModuleUsage
{
    use ModuleTrait;

    protected HordeCli $cli;
    protected Output $output;
    protected Parser $parser;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->parser = $dependencies->getInstance(Parser::class);
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
            $this->showHelp($argv);
            return true;
        }
        return false;
    }

    /**
     * Display help information
     *
     * @param array $argv Command arguments
     */
    protected function showHelp(array $argv): void
    {
        // Check if user asked for help on a specific command
        if (isset($argv[1]) && !empty($argv[1])) {
            // Check for subcommand: help create user
            if (isset($argv[2]) && !empty($argv[2])) {
                $this->showSubcommandHelp($argv[1], $argv[2]);
            } else {
                $this->showCommandHelp($argv[1]);
            }
            return;
        }

        // Show general help
        $this->cli->writeln();
        $this->cli->writeln('Hordectl - Horde Command Line Administration Tool');
        $this->cli->writeln('==============================================');
        $this->cli->writeln();
        $this->cli->writeln('Usage: hordectl [OPTIONS] COMMAND [ARGUMENTS]');
        $this->cli->writeln();
        $this->cli->writeln('Available commands:');
        $this->cli->writeln();

        // Get available modules from parent Cli
        $descriptions = $this->getCommandDescriptions();

        foreach ($descriptions as $name => $description) {
            $this->cli->writeln('  ' . str_pad($name, 15) . $description);
        }

        $this->cli->writeln();
        $this->cli->writeln('Environment:');

        // Show current target information instead of Horde installation path
        try {
            $config = new \Horde\Hordectl\ConfigManager();
            $currentTarget = $config->get('current-target');
            if ($currentTarget) {
                $this->cli->writeln('  Current target: ' . $currentTarget);
            } else {
                $this->cli->writeln('  No target configured (run: hordectl target add)');
            }
        } catch (Exception $e) {
            $this->cli->writeln('  No target configured');
        }

        $this->cli->writeln();
        $this->cli->writeln('For command-specific help, run:');
        $this->cli->writeln('  hordectl help <command>');
        $this->cli->writeln();
    }

    /**
     * Display help for a specific command
     *
     * @param string $commandName Command name
     */
    protected function showCommandHelp(string $commandName): void
    {
        // Try to load the command module
        $className = 'Horde\\Hordectl\\Command\\' . ucfirst($commandName);

        if (!class_exists($className)) {
            $this->output->error(sprintf('Unknown command: %s', $commandName));
            $this->cli->writeln();
            $this->cli->writeln('Run "hordectl help" to see available commands.');
            $this->cli->writeln();
            return;
        }

        try {
            $command = new $className($this->dependencies);

            $this->cli->writeln();
            $this->cli->writeln('Command: ' . $commandName);
            $this->cli->writeln(str_repeat('=', strlen('Command: ' . $commandName)));
            $this->cli->writeln();

            // Check if command has submodules (HasModulesTrait)
            if (method_exists($command, 'listModules')) {
                // Command has submodules - show parent help via getUsageDescription or direct output
                if (method_exists($command, 'getUsageDescription')) {
                    $description = $command->getUsageDescription();
                    if (is_array($description)) {
                        foreach ($description as $line) {
                            $this->cli->writeln($line);
                        }
                    } else {
                        $this->cli->writeln($description);
                    }
                    $this->cli->writeln();
                    return;
                }

                // Fallback: show generic subcommand help
                if (method_exists($command, 'getUsage')) {
                    $this->cli->writeln($command->getUsage());
                    $this->cli->writeln();
                }

                $this->cli->writeln("Available subcommands:");
                $this->cli->writeln();

                foreach ($command->listModules() as $module) {
                    // Get primary name - prefer getPositionalArgs over getTitle
                    if (method_exists($module, 'getPositionalArgs')) {
                        $positionals = $module->getPositionalArgs();
                        $name = !empty($positionals) ? $positionals[0] : null;
                    } else {
                        $name = null;
                    }

                    if ($name === null && method_exists($module, 'getTitle')) {
                        $name = $module->getTitle();
                    }

                    if ($name === null) {
                        $name = '(unknown)';
                    }

                    // Try getSummary, then getUsage, then generic message
                    if (method_exists($module, 'getSummary')) {
                        $summary = $module->getSummary();
                    } elseif (method_exists($module, 'getUsage')) {
                        $usage = $module->getUsage();
                        // Extract first line if multiline
                        $summary = explode("\n", $usage)[0];
                    } else {
                        $summary = '';
                    }

                    $this->cli->writeln('  ' . str_pad($name, 15) . $summary);
                }

                $this->cli->writeln();
                $this->cli->writeln("For subcommand-specific help:");
                $this->cli->writeln("  hordectl {$commandName} <subcommand> --help");
                $this->cli->writeln();
                return;
            }

            // Check if command has detailed usage description
            if (method_exists($command, 'getUsageDescription')) {
                $description = $command->getUsageDescription();
                if (is_array($description)) {
                    foreach ($description as $line) {
                        $this->cli->writeln($line);
                    }
                } else {
                    $this->cli->writeln($description);
                }
                $this->cli->writeln();
            } elseif (method_exists($command, 'getUsage')) {
                $usage = $command->getUsage();
                $this->cli->writeln($usage);
                $this->cli->writeln();
            } elseif (method_exists($command, 'getSummary')) {
                $summary = $command->getSummary();
                $this->cli->writeln($summary);
                $this->cli->writeln();
            } else {
                $this->cli->writeln('No detailed help available for this command.');
                $this->cli->writeln();
            }
        } catch (Exception $e) {
            $this->output->error('Error loading command: ' . $e->getMessage());
            $this->cli->writeln();
        }
    }

    /**
     * Display help for a specific subcommand
     *
     * @param string $commandName Parent command name
     * @param string $subcommandName Subcommand name
     */
    protected function showSubcommandHelp(string $commandName, string $subcommandName): void
    {
        // Try to load the parent command
        $className = 'Horde\\Hordectl\\Command\\' . ucfirst($commandName);

        if (!class_exists($className)) {
            $this->output->error(sprintf('Unknown command: %s', $commandName));
            $this->cli->writeln();
            return;
        }

        try {
            $command = new $className($this->dependencies);

            // Check if command has submodules
            if (!method_exists($command, 'listModules')) {
                $this->output->error(sprintf('Command "%s" has no subcommands', $commandName));
                $this->cli->writeln();
                return;
            }

            // Find the subcommand module
            foreach ($command->listModules() as $module) {
                // Try getTitle() first, then getPositionalArgs()
                $matches = [];
                if (method_exists($module, 'getTitle')) {
                    $matches[] = $module->getTitle();
                }
                if (method_exists($module, 'getPositionalArgs')) {
                    $matches = array_merge($matches, $module->getPositionalArgs());
                }

                if (in_array($subcommandName, $matches)) {
                    // Found it - show its help
                    $this->cli->writeln();
                    $this->cli->writeln("Command: {$commandName} {$subcommandName}");
                    $this->cli->writeln(str_repeat('=', strlen("Command: {$commandName} {$subcommandName}")));
                    $this->cli->writeln();

                    if (method_exists($module, 'getUsageDescription')) {
                        $description = $module->getUsageDescription();
                        if (is_array($description)) {
                            foreach ($description as $line) {
                                $this->cli->writeln($line);
                            }
                        } else {
                            $this->cli->writeln($description);
                        }
                    } elseif (method_exists($module, 'getUsage')) {
                        $this->cli->writeln($module->getUsage());
                    }

                    $this->cli->writeln();
                    return;
                }
            }

            // Subcommand not found
            $this->output->error(sprintf('Unknown subcommand: %s %s', $commandName, $subcommandName));
            $this->cli->writeln();
            $this->cli->writeln("Run 'hordectl help {$commandName}' to see available subcommands.");
            $this->cli->writeln();
        } catch (Exception $e) {
            $this->output->error('Error loading command: ' . $e->getMessage());
            $this->cli->writeln();
        }
    }

    /**
     * Get command descriptions
     *
     * @return array Map of command name to description
     */
    protected function getCommandDescriptions(): array
    {
        return [
            'help' => 'Show this help message',
            'target' => 'Manage multi-target configuration (current, list, use, add, etc.)',
            'create' => 'Create resources interactively (user, group)',
            'query' => 'Query and export Horde resources via API (requires API endpoint)',
            'import' => 'Import resources into Horde from YAML (requires API endpoint)',
            'patch' => 'Modify individual resources (requires API endpoint)',
            'configure' => 'Configure Horde subsystems (local only - requires filesystem)',
            'activate' => 'Activate Horde applications (local only - requires filesystem)',
            'test' => 'Test Horde subsystems (db, cache, session, logger, auth, jwt, all)',
            'version' => 'Show version information',
            'secret' => 'Manage API secrets (generate, show)',
        ];
    }

    /**
     * Get module usage information
     *
     * @return string
     */
    public function getUsage(): string
    {
        return 'Show help and usage information';
    }

    /**
     * Get module title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'help';
    }

    /**
     * Get detailed usage description
     *
     * @return array
     */
    public function getUsageDescription(): array
    {
        return [
            'Display help information for hordectl commands',
            '',
            'Usage:',
            '  hordectl help              Show all available commands',
            '  hordectl help <command>    Show detailed help for a specific command',
            '',
            'Examples:',
            '  hordectl help query        Show help for query command',
            '  hordectl help import       Show help for import command',
        ];
    }
}
