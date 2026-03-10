<?php

namespace Horde\Hordectl\Command;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use \Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use \Horde\Hordectl\HasModulesTrait;
use Horde\Injector\Injector;
use Horde\Argv\Option;
use Horde\Argv\Parser;
/**
 *
 * Command module to manipulate single resource entities
 */
class Patch
implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->_parser = $dependencies->getInstance(Parser::class);
        // We stop parsing after the first positional
        $this->_parser->allowInterspersedArgs = false;
    }

    public function getBaseOptions()
    {
        return
            [
                new Option(
                    '-f',
                    '--filename',
                    [
                        'action' => 'store',
                        'type' => 'string',
                        'dest' => 'filename',
                        'help'   => 'The Yaml file to read'
                    ]
                )
            ];
    }

    /**
     * Decide if this module handles the commandline
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
        if ($argv[0] != 'patch') {
            return false;
        }
    
        $parser = new Parser();
        $parser->allowInterspersedArgs = false;

        list($myArgs, $moduleArgs) = $this->handleCommandline($argv);
        if (count($moduleArgs) >= 3 && $moduleArgs[0] == 'user') {
            $username = $moduleArgs[1];
            $password = $moduleArgs[2];

            try {
                $auth = $this->dependencies->getInstance('\Horde_Auth_Base');
                $driverName = get_class($auth);

                // Check if this is the Application wrapper and introspect the wrapped driver
                if ($driverName === 'Horde_Core_Auth_Application') {
                    try {
                        $reflection = new \ReflectionClass($auth);
                        if ($reflection->hasProperty('_base')) {
                            $baseProperty = $reflection->getProperty('_base');
                            $baseProperty->setAccessible(true);
                            $baseDriver = $baseProperty->getValue($auth);

                            if ($baseDriver !== null) {
                                $baseClass = get_class($baseDriver);
                                $driverName = "Horde_Core_Auth_Application wrapping {$baseClass}";
                            }
                        }
                    } catch (\ReflectionException $e) {
                        // Reflection failed, just show the wrapper class
                    }
                }

                if ($auth->exists($username)) {
                    $this->cli->message(
                        sprintf('Updating password for user "%s" (driver: %s)', $username, $driverName),
                        'cli.message'
                    );
                    $auth->updateUser($username, $username, ['password' => $password]);
                    $this->cli->message(
                        sprintf('Successfully updated password for user "%s"', $username),
                        'cli.success'
                    );
                } else {
                    $this->cli->message(
                        sprintf('Creating new user "%s" (driver: %s)', $username, $driverName),
                        'cli.message'
                    );
                    $auth->addUser($username, ['password' => $password]);
                    $this->cli->message(
                        sprintf('Successfully created user "%s"', $username),
                        'cli.success'
                    );
                }
                return true;
            } catch (\Exception $e) {
                $this->cli->message(
                    sprintf('Error: %s', $e->getMessage()),
                    'cli.error'
                );
                return true;
            }
        }

        // No recognized subcommand
        $this->cli->writeln();
        $this->cli->writeln('Usage: hordectl patch user <username> <password>');
        $this->cli->writeln();
        $this->cli->writeln('Patch (modify) individual Horde resources.');
        $this->cli->writeln();
        $this->cli->writeln('Available subcommands:');
        $this->cli->writeln('  user    Create or update a user password');
        $this->cli->writeln();
        return false;
    }

    /**
     * Get detailed usage information
     *
     * @return string
     */
    public function getUsage()
    {
        return 'patch user <username> <password>

Patch (modify) individual Horde resources.

Available subcommands:
    user    Create or update a user password

EXAMPLES
    # Create new user
    hordectl patch user newuser secretpassword

    # Update existing user password
    hordectl patch user admin newpassword

The command shows which authentication driver is being used and reports
success or failure with clear error messages.
';
    }

    /**
     * Get short summary for command list
     *
     * @return string
     */
    public function getSummary()
    {
        return 'Modify individual resources (patch user <username> <password>)';
    }
}