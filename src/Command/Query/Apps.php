<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Hordectl\Command\Query;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;
use Exception;
use Horde_Cli;

/**
 * Query command module for applications (meta-resources)
 *
 * Lists available applications via REST API.
 * Applications are meta-resources that define other resource types.
 *
 * Inspired by Kubernetes/kubectl pattern where resource types themselves
 * are queryable objects (like CustomResourceDefinitions).
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Apps implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use AdminApiClientTrait;

    protected Horde_Cli $cli;
    protected AdminApiClient $apiClient;

    /**
     * Constructor
     *
     * @param Injector $dependencies Dependency injection container
     */
    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
    }

    /**
     * Handle command: hordectl query apps [appname]
     *
     * @param array $argv Command arguments
     * @return bool True if handled, false if not
     */
    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1) {
            return false;
        }

        // Check if this is "apps" query
        if ($argv[0] !== 'apps') {
            return false;
        }

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        try {
            $appList = $this->apiClient->listApplications();

            $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');

            if ($appList->isEmpty()) {
                $this->cli->writeln();
                $this->cli->message('No applications found', 'cli.warning');
                $this->cli->writeln();
                return true;
            }

            // Convert to YAML format - collect all apps then add once
            $allApps = [];
            foreach ($appList->toArray() as $app) {
                $allApps[] = [
                    'name' => $app->name,
                    'version' => $app->version,
                    'status' => $app->status,
                    'active' => $app->active,
                ];
            }

            $writer->addResource('builtin', 'app', $allApps);

            return true;

        } catch (Exception $e) {
            $this->cli->writeln();
            $this->cli->message('ERROR: ' . $e->getMessage(), 'cli.error');
            $this->cli->writeln();
            $this->cli->writeln('Unable to query applications via REST API.');
            $this->cli->writeln('Please check:');
            $this->cli->writeln('  - Admin API is enabled in Horde conf.php');
            $this->cli->writeln('  - admin_secret is configured in hordectl.php or Horde conf.php');
            $this->cli->writeln('  - Horde endpoint is accessible: ' . ($this->getEndpoint() ?? 'not configured'));
            $this->cli->writeln();
            return true;
        }
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

    /**
     * Get module usage information
     *
     * @return string
     */
    public function getUsage(): string
    {
        return 'Query applications (meta-resources that define resource types)';
    }

    /**
     * Get module title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'apps';
    }

    /**
     * Get module description
     *
     * @return array
     */
    public function getUsageDescription(): array
    {
        return [
            'Query applications via REST API',
            '',
            'Applications are meta-resources that define other resource types.',
            'Similar to Kubernetes CustomResourceDefinitions (CRDs).',
            '',
            'Usage:',
            '  hordectl query apps              # List all applications',
            '',
            'Output is in YAML format, suitable for inspection or further processing.',
            '',
            'Requirements:',
            '  - Horde admin API must be enabled (admin_api.enabled = true)',
            '  - admin_secret must be configured',
            '  - Horde endpoint must be accessible',
        ];
    }
}
