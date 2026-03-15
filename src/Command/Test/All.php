<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Argv\Parser;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Exception;
use Horde_Cli;

/**
 * Test all subsystems via REST API
 */
class All implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use AdminApiClientTrait;
    use HealthCheckDisplayTrait;

    protected Horde_Cli $cli;
    protected AdminApiClient $apiClient;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->parser = $dependencies->getInstance(Parser::class);
        $this->parser->allowInterspersedArgs = false;
    }

    public function handle(array $argv = [])
    {
        if (count($argv) < 1 || $argv[0] != 'all') {
            return false;
        }

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        $this->cli->writeln();
        $this->cli->writeln('All Subsystems Test');
        $this->cli->writeln('===================');
        $this->cli->writeln();

        try {
            $results = $this->apiClient->checkAllHealth();

            $subsystems = ['db' => 'Database', 'cache' => 'Cache', 'session' => 'Session',
                'logger' => 'Logger', 'auth' => 'Auth', 'jwt' => 'JWT'];

            foreach ($subsystems as $key => $title) {
                if (isset($results[$key])) {
                    $result = $results[$key];
                    $this->cli->writeln($title . ':');
                    $this->cli->writeln(str_repeat('-', strlen($title) + 1));

                    if ($result->isOk()) {
                        $this->cli->message('  Status: ' . $this->cli->green('OK'), 'cli.message');
                    } elseif ($result->isWarning()) {
                        $this->cli->message('  Status: ' . $this->cli->yellow('WARNING'), 'cli.message');
                    } else {
                        $this->cli->message('  Status: ' . $this->cli->red('ERROR'), 'cli.message');
                    }

                    $this->cli->message('  Message: ' . $result->message, 'cli.message');
                    $this->cli->writeln();
                }
            }

            return true;
        } catch (Exception $e) {
            $this->displayApiError($e);
            return true;
        }
    }
}
