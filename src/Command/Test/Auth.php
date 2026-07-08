<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Hordectl\Exception\TestModuleException;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Exception;

/**
 * Test auth via REST API
 */
class Auth implements Module, ModuleUsage
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
        if (count($argv) < 1 || $argv[0] != 'auth') {
            return false;
        }

        // Check if target has API endpoint configured

        $target = $this->getActiveTarget();

        if (!$target->supportsApiCommands()) {

            $this->cli->writeln();

            $this->output->warn('Test skipped: API endpoint not configured');

            $this->cli->writeln();

            $this->cli->writeln('The auth test requires a configured Horde web endpoint.');

            $this->cli->writeln();

            $this->cli->writeln('To configure the endpoint:');

            $this->cli->writeln('  1. Activate your Horde installation:');

            $this->cli->writeln('     hordectl activate');

            $this->cli->writeln();

            $this->cli->writeln('  2. Add the web endpoint to your target:');

            $this->cli->writeln("     hordectl target update {$target->name} --endpoint=http://localhost/horde");

            $this->cli->writeln();

            $this->cli->writeln('  3. Generate an admin secret:');

            $this->cli->writeln('     hordectl secret generate');

            $this->cli->writeln();

            return true;  // Handled but skipped (not a failure)

        }

        $this->apiClient = $this->createApiClientFromTarget($target);

        $this->cli->writeln();
        $this->cli->writeln('Authentication Test');
        $this->cli->writeln(str_repeat('=', strlen('Authentication Test')));
        $this->cli->writeln();

        try {
            $result = $this->apiClient->checkHealth('auth');
            $this->displayHealthCheck($result);
            return true;
        } catch (Exception $e) {
            $this->displayApiError($e);
            throw new TestModuleException("Auth test failed: {$e->getMessage()}", 0, $e);
        }
    }
}
