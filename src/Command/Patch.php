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
        $this->_parser = $dependencies->getInstance('\Horde_Argv_Parser');
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
            $auth = $this->dependencies->getInstance('\Horde_Auth_Base');
            if ($auth->exists($username)) {
                $auth->updateUser($username, $username, ['password' => $password]);
            } else {
                $auth->addUser($username, ['password' => $password]);
            }
        }
        return false;
    }
}