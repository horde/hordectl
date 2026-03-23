<?php

namespace Horde\Hordectl\Command\Query;

use Exception;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;
use RuntimeException;

/**
 * Query command module for Horde users via Admin REST API
 *
 * Uses modern AdminApiClient instead of legacy UserRepo.
 */
class User implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use AdminApiClientTrait;

    protected HordeCli $cli;
    protected Output $output;
    private AdminApiClient $apiClient;

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

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');

        try {
            // Check if a specific username was provided
            if (isset($argv[1]) && !empty($argv[1])) {
                $username = $argv[1];

                // Get single user via API
                $user = $this->apiClient->getUser($username);

                // Convert to legacy YAML format
                $items = [[
                    'userUid' => $user->username,
                    'identities' => $user->identities,
                ]];

                $writer->addResource('builtin', 'user', $items);
            } else {
                // Export all users
                $page = 1;
                $perPage = 100;
                $allUsers = [];

                do {
                    $userList = $this->apiClient->listUsers($page, $perPage);

                    foreach ($userList->toArray() as $user) {
                        $allUsers[] = [
                            'userUid' => $user->username,
                            'identities' => $user->identities,
                        ];
                    }

                    $page++;
                } while ($userList->hasNext);

                $writer->addResource('builtin', 'user', $allUsers);
            }
        } catch (RuntimeException $e) {
            // Check if it's a 404 error (user not found)
            if (strpos($e->getMessage(), 'USER_NOT_FOUND') !== false) {
                $this->output->error(
                    sprintf('User "%s" not found', $argv[1] ?? 'unknown')
                );
            } else {
                $this->output->error(
                    sprintf('Error querying users: %s', $e->getMessage())
                );
                // Re-throw for debugging
                throw $e;
            }
            return false;  // Changed from true to false to indicate failure
        } catch (Exception $e) {
            $this->output->error(
                sprintf('Error querying users: %s', $e->getMessage())
            );
            // Re-throw for debugging
            throw $e;
            return false;  // Changed from true to false
        }

        return true;
    }
}
