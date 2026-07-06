<?php

namespace Horde\Hordectl\Command\Query;

use Exception;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
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
 * Query command module for Horde permissions via Admin REST API
 *
 * Uses modern AdminApiClient instead of legacy PermsRepo.
 */
class Permission implements Module, ModuleUsage
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
     *   hordectl query permission              - Export all permissions
     *   hordectl query permission <name>       - Export specific permission
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
        if ($argv[0] != 'permission') {
            return false;
        }

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');

        try {
            // Check if a specific permission name was provided
            if (isset($argv[1]) && !empty($argv[1])) {
                $name = $argv[1];

                // Get single permission via API
                $permission = $this->apiClient->getPermission($name);

                // Convert to YAML format
                $items = [$permission->toArray()];

                $writer->addResource('builtin', 'permission', $items);
            } else {
                // Export all permissions
                $permissionList = $this->apiClient->listPermissions();

                $items = [];
                foreach ($permissionList->toArray() as $permission) {
                    $items[] = $permission->toArray();
                }

                $writer->addResource('builtin', 'permission', $items);
            }
        } catch (RuntimeException $e) {
            // 404 gets a targeted message; everything else falls
            // through to the shared "unable to query via REST API"
            // block so the user sees the same troubleshooting hints
            // query apps and query registry emit.
            if (strpos($e->getMessage(), 'PERMISSION_NOT_FOUND') !== false) {
                $this->cli->writeln();
                $this->output->error(
                    sprintf('Permission "%s" not found', $argv[1] ?? 'unknown')
                );
                $this->cli->writeln();
                return true;
            }
            $this->emitApiFailure($e);
            return true;
        } catch (Exception $e) {
            $this->emitApiFailure($e);
            return true;
        }

        return true;
    }

    /**
     * Print the shared "unable to query via REST API" block.
     *
     * Mirrors the shape used by Query\Apps and Query\Registry.
     * Consistent output helps admins diagnose config issues
     * regardless of which subcommand tripped the failure.
     */
    private function emitApiFailure(Exception $e): void
    {
        $this->cli->writeln();
        $this->output->error($e->getMessage());
        $this->cli->writeln();
        $this->cli->writeln('Unable to query permissions via REST API.');
        $this->cli->writeln('Please check:');
        $this->cli->writeln('  - Admin API is enabled in Horde conf.php');
        $this->cli->writeln('  - admin_secret is configured in hordectl.php or Horde conf.php');
        $this->cli->writeln('  - Horde endpoint is accessible: ' . ($this->getEndpoint() ?? 'not configured'));
        $this->cli->writeln();
    }

    /**
     * Get configured endpoint for error messages
     *
     * @return string|null
     */
    private function getEndpoint(): ?string
    {
        try {
            $configManager = new \Horde\Hordectl\ConfigManager();
            return $configManager->get('admin_api')['endpoint'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
}
