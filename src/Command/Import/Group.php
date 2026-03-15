<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Import;

use Horde\Argv\Parser;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;
use Horde_Cli;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use RuntimeException;

/**
 * Import command module for Horde groups via Admin REST API
 */
class Group implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use AdminApiClientTrait;

    protected Horde_Cli $cli;
    private AdminApiClient $apiClient;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(Horde_Cli::class);
        $this->parser = $dependencies->getInstance(Parser::class);
        // We stop parsing after the first positional
        $this->parser->allowInterspersedArgs = false;
    }

    /**
     * Import group resources from YAML
     *
     * Expected YAML format:
     *   apps:
     *     builtin:
     *       resources:
     *         group:
     *           items:
     *             - groupName: groupname
     *               state: present  # or 'absent' to delete (default: present)
     *               members:
     *                 - username1
     *                 - username2
     *
     * @param string $app App name (must be 'builtin')
     * @param string $resource Resource type (must be 'group')
     * @param array $tree Full YAML tree
     * @return bool True if this module handled the resource
     */
    public function import(string $app, string $resource, array $tree)
    {
        if ($app != 'builtin' || $resource != 'group') {
            return false;
        }

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        $items = $tree['apps']['builtin']['resources']['group']['items'] ?? [];

        if (empty($items)) {
            $this->cli->message('No groups to import', 'cli.warning');
            return true;
        }

        $created = 0;
        $updated = 0;
        $deleted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($items as $item) {
            $groupName = $item['groupName'] ?? null;
            $members = $item['members'] ?? [];
            $state = $item['state'] ?? 'present';

            if (!$groupName) {
                $this->cli->message('Skipping group item without groupName', 'cli.warning');
                $skipped++;
                continue;
            }

            try {
                // Check if group exists
                $exists = false;
                try {
                    $this->apiClient->getGroup($groupName);
                    $exists = true;
                } catch (RuntimeException $e) {
                    $exists = false;
                }

                if ($state === 'absent') {
                    // Delete group
                    if ($exists) {
                        $this->apiClient->deleteGroup($groupName);
                        $this->cli->message(
                            sprintf('Deleted group: %s', $groupName),
                            'cli.success'
                        );
                        $deleted++;
                    } else {
                        $this->cli->message(
                            sprintf('Group "%s" already absent', $groupName),
                            'cli.warning'
                        );
                        $skipped++;
                    }
                } elseif ($state === 'present') {
                    if ($exists) {
                        // Group exists - update members using incremental add/remove
                        $memberChanges = $this->updateGroupMembers($groupName, $members);
                        $this->cli->message(
                            sprintf(
                                'Updated group "%s": %d added, %d removed',
                                $groupName,
                                $memberChanges['added'],
                                $memberChanges['removed']
                            ),
                            'cli.success'
                        );
                        $updated++;
                    } else {
                        // Group doesn't exist - create
                        $group = $this->apiClient->createGroup($groupName);

                        // Set members if any
                        if (!empty($members)) {
                            $this->apiClient->setGroupMembers($group->id, $members);
                        }

                        $this->cli->message(
                            sprintf('Created group "%s" with %d member(s)', $groupName, count($members)),
                            'cli.success'
                        );
                        $created++;
                    }
                } else {
                    $this->cli->message(
                        sprintf('Invalid state "%s" for group "%s" - must be "present" or "absent"', $state, $groupName),
                        'cli.error'
                    );
                    $errors++;
                }
            } catch (RuntimeException $e) {
                $this->cli->message(
                    sprintf('Error importing group "%s": %s', $groupName, $e->getMessage()),
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

    /**
     * Update group members incrementally using add/remove operations
     *
     * @param string $groupName Group name/identifier
     * @param array $desiredMembers Desired member list from YAML
     * @return array Stats: ['added' => int, 'removed' => int]
     */
    private function updateGroupMembers(string $groupName, array $desiredMembers): array
    {
        $stats = ['added' => 0, 'removed' => 0];

        // Get current members
        $currentMembers = $this->apiClient->getGroupMembers($groupName);

        // Calculate differences
        $toAdd = array_diff($desiredMembers, $currentMembers);
        $toRemove = array_diff($currentMembers, $desiredMembers);

        // Add new members
        foreach ($toAdd as $username) {
            $this->apiClient->addGroupMember($groupName, $username);
            $stats['added']++;
        }

        // Remove old members
        foreach ($toRemove as $username) {
            $this->apiClient->removeGroupMember($groupName, $username);
            $stats['removed']++;
        }

        return $stats;
    }
}
