<?php

namespace Horde\Hordectl\Command;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Exception;
use Horde_Cli;

/**
 *
 * Help command module implements CLI help/usage
 */
class Help implements Module, ModuleUsage
{
    use ModuleTrait;

    protected Horde_Cli $cli;
    protected Parser $parser;

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
            $this->showCommandHelp($argv[1]);
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
            $this->cli->message(sprintf('Unknown command: %s', $commandName), 'cli.error');
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
            $this->cli->message('Error loading command: ' . $e->getMessage(), 'cli.error');
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
