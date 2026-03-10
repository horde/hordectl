<?php

namespace Horde\Hordectl\Command\Query;
use \Horde_Cli_Modular_Module as Module;
use \Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use \Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
/**
 *
 * Query command module for Horde users
 */
class User
implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;
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
     * Usage:
     *   hordectl query user              - Export all users
     *   hordectl query user <username>   - Export specific user
     *
     * @params array $globalOpts  Commandline Options already parsed by previous levels
     * @params array $argv        The arguments for the parser to digest
     */
    public function handle(array $argv = [])
    {
        // Do not act on empty argv
        if (count($argv) < 1) {
            return false;
        }
        if ($argv[0] != 'user') {
            return false;
        }

        $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');
        $exporter = $this->dependencies->getInstance('UserRepo');

        // Check if a specific username was provided
        if (isset($argv[1]) && !empty($argv[1])) {
            $username = $argv[1];

            try {
                $auth = $this->dependencies->getInstance('\Horde_Auth_Base');

                if (!$auth->exists($username)) {
                    $this->cli->message(
                        sprintf('User "%s" not found', $username),
                        'cli.error'
                    );
                    return true;
                }

                // Export only the specified user
                $allItems = $exporter->export();
                $items = array_filter($allItems, function($item) use ($username) {
                    return isset($item['userUid']) && $item['userUid'] === $username;
                });

                if (empty($items)) {
                    $this->cli->message(
                        sprintf('User "%s" exists but could not be exported', $username),
                        'cli.error'
                    );
                    return true;
                }

                $writer->addResource('builtin', 'user', array_values($items));
            } catch (\Exception $e) {
                $this->cli->message(
                    sprintf('Error querying user: %s', $e->getMessage()),
                    'cli.error'
                );
                return true;
            }
        } else {
            // Export all users
            $items = $exporter->export();
            $writer->addResource('builtin', 'user', $items);
        }

        return true;
    }
}