<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Exception;

/**
 * Test all subsystems via REST API
 */
class All implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use AdminApiClientTrait;
    use HealthCheckDisplayTrait;

    protected HordeCli $cli;
    protected Output $output;
    protected AdminApiClient $apiClient;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
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
                        $this->output->info('  Status: OK');
                    } elseif ($result->isWarning()) {
                        $this->output->warn('  Status: WARNING');
                    } else {
                        $this->output->error('  Status: ERROR');
                    }

                    $this->output->info('  Message: ' . $result->message);
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
