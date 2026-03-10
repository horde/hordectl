<?php

namespace Horde\Hordectl\Command;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use \Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
/**
 *
 * Help command module implements CLI help/usage
 */
class Help
implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;
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
        $this->cli->writeln('  Horde installation: ' . $this->dependencies->findHordePath());

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

            // Check if command has getUsage method
            if (method_exists($command, 'getUsage')) {
                $usage = $command->getUsage();
                $this->cli->writeln($usage);
            } elseif (method_exists($command, 'getSummary')) {
                $summary = $command->getSummary();
                $this->cli->writeln($summary);
                $this->cli->writeln();
            } else {
                $this->cli->writeln('No detailed help available for this command.');
                $this->cli->writeln();
            }
        } catch (\Exception $e) {
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
            'query' => 'Query and export Horde resources (user, group, app, permission)',
            'import' => 'Import resources into Horde from YAML',
            'patch' => 'Modify individual resources (patch user <username> <password>)',
            'configure' => 'Configure Horde subsystems and settings',
            'activate' => 'Activate Horde applications',
            'test' => 'Test Horde subsystems (db, cache, session, logger, auth, jwt, all)',
        ];
    }
}