<?php

/**
 * hordectl CLI Root module
 */

namespace Horde\Hordectl;

use Horde\Argv\IndentedHelpFormatter;
use Horde\Argv\Option;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Exception\HordeException;
use Horde\Injector\Injector;
use Horde\Injector\TopLevel;
use Horde_Cli_Modular as Cli_Modular;
use Horde_Cli_Modular_Module as Module;
use Horde_String;

/**
 * Hordectl CLI Root Module
 *
 * The basic idea of hordectl is a very modular approach.
 * Everything is either a leaf module or a parent module regardless of its level.
 *
 * Apart from the static Cli::main method which handles all the initial setup,
 * Horde\Hordectl\Cli is just a parent module like any other
 *
 * Modules know their runtime parents and their parent's parsed config but they may appear in any other part of the tree if called by another frontend
 *
 * It is safe for a parent to assume its builtin children exist (like the help submodule)
 *
 * In fact, hordectl is a spinoff from an application specific solution
 */
class Cli implements Module
{
    use HordectlModuleTrait;
    use HasModulesTrait;

    protected HordeCli|Modular $cli;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->_parser = $dependencies->getInstance(Parser::class);
        // We stop parsing after the first positional
        $this->_parser->allowInterspersedArgs = false;
        $prefix = '\Horde\Hordectl\Command';
        $directory = dirname(__FILE__) . '/Command/';
        $exclude = [];
        $this->_initModules($dependencies, $prefix, $directory, $exclude);
    }

    /**
     * Get base options for global flags
     *
     * Global --target flag allows temporary target override without changing
     * the persistent current target configuration.
     *
     * Usage: hordectl --target=NAME COMMAND [ARGS]
     *
     * Note: Global options must come BEFORE the command name due to parser
     * configuration (allowInterspersedArgs = false).
     *
     * @return array Array of Option objects for global flags
     */
    public function getBaseOptions(): array
    {
        return [
            new Option(
                '--target',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'dest' => 'override_target',
                    'metavar' => 'NAME',
                    'help' => 'Temporarily use specified target (does not change current)',
                ]
            ),
        ];
    }

    // Setup a Horde_Cli_Modular, a Parser, setup self as root module
    public static function main(array $parameters = [])
    {
        // Use modern Horde\Cli\Cli
        $cli = new HordeCli(['pager' => true]);

        // Setup dependencies (no Horde bootstrap - target-based approach only)
        $dependencies = new Dependencies(new TopLevel());
        $dependencies->setInstance(HordeCli::class, $cli);
        // Also register as legacy name for backward compatibility during migration
        $dependencies->setInstance('\Horde_Cli', $cli);

        // Setup the CLI Parser
        $parser = $dependencies->getInstance(Parser::class);
        $parser->allowInterspersedArgs = false;

        // Setup the modules system
        $modular = self::_prepareModular($dependencies);

        // Setup self as the root module
        $CliModule = $dependencies->getInstance('\Horde\Hordectl\Cli');

        array_shift($parameters['argv']);
        if (count($parameters['argv']) < 1) {
            if ($CliModule->isRootModule()) {
                $cli->writeln($CliModule->getTitle() . " is the root module");
            }
            // preliminary index of commands
            $cli->writeln("Found Modules:");
            foreach ($CliModule->listModules() as $module) {
                $cli->writeln(Horde_String::lower($module->getTitle()));
            }
        }

        // Fetch the cli module's direct parameters and run its handle method
        $globalOpts = $CliModule->handleCommandline($parameters['argv']);

        // Handle global --target flag if provided
        if (isset($globalOpts[0]->override_target) && !empty($globalOpts[0]->override_target)) {
            $dependencies->setInstance('hordectl.target_override', $globalOpts[0]->override_target);
        }

        $CliModule->handle($globalOpts[1]);
    }

    public function handle(array $argv = []): bool
    {
        // Each module will decide if it is responsible for the entered command
        // Cycle through modules and call each module's handle method.
        try {
            $ran = false;
            $modules = $this->listModules();
            foreach ($modules as $class => $module) {
                $result = $module->handle($argv);
                $ran |= $result;
            }
        } catch (HordeException $e) {
            return false;
        }

        // Something didn't work as expected.
        if (!$ran) {
            $this->showUsage();
        }
        return $ran;
    }

    /**
     * Show usage information when no module handles the command
     */
    protected function showUsage(): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Usage: hordectl [OPTIONS] COMMAND [ARGUMENTS]');
        $this->cli->writeln();
        $this->cli->writeln('Global options:');
        $this->cli->writeln('  --target=<name>    Temporarily use specified target (does not change current)');
        $this->cli->writeln();
        $this->cli->writeln('Available commands:');

        // Build list of commands with their descriptions
        $commands = [];
        foreach ($this->listModules() as $class => $module) {
            // Get module name - extract from class name if getTitle() not available
            if (method_exists($module, 'getTitle')) {
                $name = strtolower($module->getTitle());
            } else {
                $name = strtolower(basename(str_replace('\\', '/', $class)));
            }
            $commands[$name] = $this->getModuleDescription($name);
        }

        // Sort alphabetically by command name
        ksort($commands);

        // Display sorted commands with green command names
        foreach ($commands as $name => $description) {
            $coloredName = $this->cli->green($name);
            $this->cli->writeln('  ' . str_pad($coloredName, 15 + strlen($coloredName) - strlen($name)) . ' ' . $description);
        }

        $this->cli->writeln();
        $this->cli->writeln('Run \'hordectl help\' for more information.');
        $this->cli->writeln();
    }

    /**
     * Get a brief description for a module
     *
     * Returns a one-line summary, not the full detailed description.
     */
    protected function getModuleDescription(string $name): string
    {
        // Hardcoded brief descriptions - one line only
        $descriptions = [
            'activate' => 'Activate Horde installation by copying default configuration',
            'configure' => 'Configure Horde subsystems and settings',
            'help' => 'Show help and usage information',
            'import' => 'Import resources into Horde from YAML',
            'patch' => 'Modify individual Horde resources',
            'query' => 'Query and export Horde resources as YAML',
            'secret' => 'Manage admin_secret for REST API authentication',
            'test' => 'Test Horde subsystems (db, cache, session, logger, auth, jwt)',
            'version' => 'Display version information',
        ];

        return $descriptions[$name] ?? '';
    }

    /**
      * Prepare the modular CLI instance.
      *
      * Adapted from Horde git-tools CLI
      * @param  Injector $dependencies  The dependency container.
      *
      * @return Cli_Modular  The modular CLI object.
      */
    protected static function _prepareModular($dependencies)
    {
        // The modular CLI helper.
        $formatter = new IndentedHelpFormatter();
        $modular = new Cli_Modular([
            'parser' => ['usage' => '[OPTIONS] COMMAND [ARGUMENTS]
  ' . $formatter->highlightOption('COMMAND') . ' - Selects the command to perform. This is a list of possible commands:
',
            ],
            'modules' => [
                'directory' => __DIR__ . '/Command/',
                'exclude' => 'Base',
            ],
            'provider' => [
                'prefix' => '\Horde\Hordectl\Command\\',
                'dependencies' => $dependencies,
            ],
            'cli' => $dependencies->getInstance('\Horde_Cli'),
        ]);
        return $modular;
    }
}
