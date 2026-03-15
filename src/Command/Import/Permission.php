<?php

namespace Horde\Hordectl\Command\Import;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Injector\Injector;
use Horde\Argv\Parser;
use Horde_Cli;
use RuntimeException;

/**
 * Import command module for Horde permissions via Admin REST API
 *
 * Uses modern AdminApiClient instead of legacy PermsRepo.
 */
class Permission implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use AdminApiClientTrait;

    protected Horde_Cli $cli;
    private AdminApiClient $apiClient;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->parser = $dependencies->getInstance(Parser::class);
        // We stop parsing after the first positional
        $this->parser->allowInterspersedArgs = false;
    }

    /**
     * Import permission resources from YAML
     *
     * Expected YAML format:
     *   apps:
     *     builtin:
     *       resources:
     *         permission:
     *           items:
     *             - name: turba:sources
     *               state: present  # or 'absent' to delete (default: present)
     *               type: matrix
     *               data:
     *                 users: []
     *                 groups:
     *                   - id: 8
     *                     name: testgroup2
     *                     permissions:
     *                       show: true
     *                       read: true
     *                       edit: false
     *                       delete: false
     *                 default:
     *                   show: true
     *                   read: true
     *                   edit: true
     *                   delete: true
     *                 guest: {...}
     *                 creator: {...}
     *
     * @param string $app App name (must be 'builtin')
     * @param string $resource Resource type (must be 'permission')
     * @param array $tree Full YAML tree
     * @return bool True if this module handled the resource
     */
    public function import(string $app, string $resource, array $tree)
    {
        if ($app != 'builtin' || $resource != 'permission') {
            return false;
        }

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        $items = $tree['apps']['builtin']['resources']['permission']['items'] ?? [];

        if (empty($items)) {
            $this->cli->message('No permissions to import', 'cli.warning');
            return true;
        }

        $created = 0;
        $updated = 0;
        $deleted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($items as $item) {
            $name = $item['name'] ?? null;
            $type = $item['type'] ?? 'matrix';
            $data = $item['data'] ?? [];
            $state = $item['state'] ?? 'present';

            if (!$name) {
                $this->cli->message('Skipping permission item without name', 'cli.warning');
                $skipped++;
                continue;
            }

            try {
                // Check if permission exists
                $exists = false;
                try {
                    $this->apiClient->getPermission($name);
                    $exists = true;
                } catch (RuntimeException $e) {
                    $exists = false;
                }

                if ($state === 'absent') {
                    // Delete permission
                    if ($exists) {
                        $this->apiClient->deletePermission($name);
                        $this->cli->message(
                            sprintf('Deleted permission: %s', $name),
                            'cli.success'
                        );
                        $deleted++;
                    } else {
                        $this->cli->message(
                            sprintf('Permission "%s" already absent', $name),
                            'cli.warning'
                        );
                        $skipped++;
                    }
                } elseif ($state === 'present') {
                    if ($exists) {
                        // Update existing permission
                        $this->apiClient->updatePermission($name, $data);
                        $this->cli->message(
                            sprintf('Updated permission: %s', $name),
                            'cli.success'
                        );
                        $updated++;
                    } else {
                        // Create new permission
                        $this->apiClient->createPermission($name, $type, $data);
                        $this->cli->message(
                            sprintf('Created permission: %s', $name),
                            'cli.success'
                        );
                        $created++;
                    }
                } else {
                    $this->cli->message(
                        sprintf('Invalid state "%s" for permission "%s" - must be "present" or "absent"', $state, $name),
                        'cli.error'
                    );
                    $errors++;
                }
            } catch (RuntimeException $e) {
                $this->cli->message(
                    sprintf('Error importing permission "%s": %s', $name, $e->getMessage()),
                    'cli.error'
                );
                $errors++;
            }
        }

        // Summary
        $this->cli->writeln();
        $this->cli->message(
            sprintf(
                'Import summary: %d created, %d updated, %d deleted, %d skipped, %d errors',
                $created,
                $updated,
                $deleted,
                $skipped,
                $errors
            ),
            'cli.success'
        );

        return true;
    }
}
