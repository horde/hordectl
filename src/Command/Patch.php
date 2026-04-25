<?php

namespace Horde\Hordectl\Command;

use Horde\Argv\Option;
use Horde\Argv\Parser;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HasModulesTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Exception;
use Horde\Cli\Cli as HordeCli;
use RuntimeException;

/**
 *
 * Command module to manipulate single resource entities
 */
class Patch implements Module, ModuleUsage
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
        $this->_parser = $dependencies->getInstance(Parser::class);
        // We stop parsing after the first positional
        $this->_parser->allowInterspersedArgs = false;
    }

    public function getBaseOptions(): array
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
                        'help'   => 'The Yaml file to read',
                    ]
                ),
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

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        $parser = new Parser();
        $parser->allowInterspersedArgs = false;

        [$myArgs, $moduleArgs] = $this->handleCommandline($argv);
        if (count($moduleArgs) >= 3 && $moduleArgs[0] == 'user') {
            $username = $moduleArgs[1];
            $password = $moduleArgs[2];

            try {
                // Check if user exists via REST API
                $exists = false;
                try {
                    $this->apiClient->getUser($username);
                    $exists = true;
                } catch (RuntimeException $e) {
                    // User doesn't exist
                    $exists = false;
                }

                if ($exists) {
                    $this->output->info(
                        sprintf('Updating password for user "%s"', $username)
                    );
                    $this->apiClient->patchUserPassword($username, $password);
                    $this->output->ok(
                        sprintf('Successfully updated password for user "%s"', $username)
                    );
                } else {
                    $this->output->error(
                        'User creation not supported via REST API. Use import command instead.'
                    );
                    $this->output->error(
                        sprintf('User "%s" does not exist and cannot be created via patch command', $username)
                    );
                }
                return true;
            } catch (Exception $e) {
                $this->output->error(
                    sprintf('Error: %s', $e->getMessage())
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
        $this->cli->writeln('  user    Update an existing user password (via Admin REST API)');
        $this->cli->writeln();
        $this->cli->writeln('NOTE: User creation not supported. Use import command.');
        $this->cli->writeln();
        return false;
    }

    /**
     * Get detailed usage information
     *
     * @return string
     */
    public function getUsage(): string
    {
        return 'patch user <username> <password>

Patch (modify) individual Horde resources.

Available subcommands:
    user    Update an existing user password (via Admin REST API)

EXAMPLES
    # Update existing user password
    hordectl patch user admin newpassword

NOTE: User creation is not supported via the patch command.
      Use the import command to create new users.

The command uses the Admin REST API and reports success or failure
with clear error messages.
';
    }

    /**
     * Get short summary for command list
     *
     * @return string
     */
    public function getSummary(): string
    {
        return 'Modify individual resources via Admin REST API (requires API endpoint)';
    }
}
