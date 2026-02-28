<?php
/**
 * hordectl CLI Root module
 */

namespace Horde\Hordectl;
use Horde\Injector\Injector;
use Horde\Injector\TopLevel;
use \Horde_Cli_Modular as Cli_Modular;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Argv_IndentedHelpFormatter as IndentedHelpFormatter;
use \Horde_Argv_Parser as Parser;
use Horde_Cli;

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

    protected Horde_Cli|Modular $cli;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->_parser = $dependencies->getInstance('\Horde_Argv_Parser');
        // We stop parsing after the first positional
        $this->_parser->allowInterspersedArgs = false;
        $prefix = '\Horde\Hordectl\Command';
        $directory = dirname(__FILE__) . '/Command/';
        $exclude = [];
        $this->_initModules($dependencies, $prefix, $directory, $exclude);
    }

    // Setup a Horde_Cli_Modular, a Parser, setup self as root module
    public static function main(array $parameters = array())
    {
        // Use plain Horde Injector as long as we have no need to wrap it into something more specific
        $cli = new \Horde_Cli(array('pager' => true));
        try {
            $dependencies = new Dependencies(new TopLevel);
            $dependencies->setInstance('\Horde_Cli', $cli);
            $dependencies->bootstrapHorde();
        } catch (HordeNotFoundException $e) {
            $cli->writeln("Error: Horde installation not found. Please set the HORDE_GIT_DIR or HORDE_BASE environment variables.");
            return false;
        }

        // TODO: How to handle uninitialized horde? Not all commands may need a working horde
        // Setup the CLI Parser.
        $parser = $dependencies->getInstance('\Horde_Argv_Parser');
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
                $cli->writeln(\Horde_String::lower($module->getTitle()));
            }
        }
        // Fetch the cli module's direct parameters and run its handle method
        $globalOpts = $CliModule->handleCommandline($parameters['argv']);
        $CliModule->handle($globalOpts[1]);
    }

    public function handle(array $argv = []) : bool
    {
        // Each module will decide if it is responsible for the entered command
        // Cycle through modules and call each module's handle method.
        try {
            $ran = false;
            foreach ($this->listModules() as $class => $module) {
                $ran |= $module->handle($argv);
            }
        } catch (\Horde_Exception $e) {
            return false;
        }

        // Something didn't work as expected.
        if (!$ran) {
            // TODO: Get more useful help
            $this->cli->message('No Module ran', 'cli.error');
        }
        return $ran;
    }

   /**
     * Prepare the modular CLI instance.
     *
     * Adapted from Horde git-tools CLI
     * @param  Injector $dependencies  The dependency container.
     *
     * @return \Horde_Cli_Modular  The modular CLI object.
     */
    protected static function _prepareModular($dependencies)
    {
        // The modular CLI helper.
        $formatter = new IndentedHelpFormatter();
        $modular = new Cli_Modular(array(
            'parser' => array('usage' => '[OPTIONS] COMMAND [ARGUMENTS]
  ' . $formatter->highlightOption('COMMAND') . ' - Selects the command to perform. This is a list of possible commands:
'
            ),
            'modules' => array(
                'directory' => __DIR__ . '/Command/',
                'exclude' => 'Base'
            ),
            'provider' => array(
                'prefix' => '\Horde\Hordectl\Command\\',
                'dependencies' => $dependencies
            ),
            'cli' => $dependencies->getInstance('\Horde_Cli'),
        ));
        return $modular;
    }
}