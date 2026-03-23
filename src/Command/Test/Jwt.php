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
 * Test jwt via REST API
 */
class Jwt implements Module, ModuleUsage
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
        if (count($argv) < 1 || $argv[0] != 'jwt') {
            return false;
        }

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        $this->cli->writeln();
        $this->cli->writeln('JWT Test');
        $this->cli->writeln(str_repeat('=', strlen('JWT Test')));
        $this->cli->writeln();

        try {
            $result = $this->apiClient->checkHealth('jwt');
            $this->displayHealthCheck($result);
            return true;
        } catch (Exception $e) {
            $this->displayApiError($e);
            return true;
        }
    }
}
