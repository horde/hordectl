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
 * Import command module for Horde users via Admin REST API
 *
 * Uses modern AdminApiClient instead of legacy UserRepo.
 */
class User implements Module, ModuleUsage
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
     * Import user resources from YAML
     *
     * Expected YAML format:
     *   apps:
     *     builtin:
     *       resources:
     *         user:
     *           items:
     *             - userUid: username
     *               state: present  # or 'absent' to delete (default: present)
     *               password: secret  # required for creation
     *               identities:
     *                 - id: Personal Identity
     *                   fullname: Full Name
     *                   from_addr: email@example.com
     *                   state: present  # or 'absent' to delete
     *                   default: true   # set as default identity
     *
     * @param string $app App name (must be 'builtin')
     * @param string $resource Resource type (must be 'user')
     * @param array $tree Full YAML tree
     * @return bool True if this module handled the resource
     */
    public function import(string $app, string $resource, array $tree)
    {
        if ($app != 'builtin' || $resource != 'user') {
            return false;
        }

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        $items = $tree['apps']['builtin']['resources']['user']['items'] ?? [];

        if (empty($items)) {
            $this->cli->message('No users to import', 'cli.warning');
            return true;
        }

        $created = 0;
        $updated = 0;
        $deleted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($items as $item) {
            $username = $item['userUid'] ?? null;

            if (!$username) {
                $this->cli->message('Skipping user item without userUid', 'cli.warning');
                $skipped++;
                continue;
            }

            $state = $item['state'] ?? 'present';

            try {
                // Check if user exists
                $exists = false;
                try {
                    $this->apiClient->getUser($username);
                    $exists = true;
                } catch (RuntimeException $e) {
                    $exists = false;
                }

                if ($state === 'absent') {
                    // Delete user
                    if ($exists) {
                        $this->apiClient->deleteUser($username);
                        $this->cli->message(
                            sprintf('Deleted user: %s', $username),
                            'cli.success'
                        );
                        $deleted++;
                    } else {
                        $this->cli->message(
                            sprintf('User "%s" already absent', $username),
                            'cli.warning'
                        );
                        $skipped++;
                    }
                } elseif ($state === 'present') {
                    if ($exists) {
                        // User exists - update identities
                        $identityResult = $this->handleIdentities($username, $item['identities'] ?? []);
                        if ($identityResult['updated'] > 0 || $identityResult['deleted'] > 0) {
                            $msg = sprintf(
                                'Updated user "%s": %d identities updated, %d deleted',
                                $username,
                                $identityResult['updated'],
                                $identityResult['deleted']
                            );
                            if ($identityResult['errors'] > 0) {
                                $msg .= sprintf(' (%d errors)', $identityResult['errors']);
                            }
                            $this->cli->message($msg, 'cli.success');
                            $updated++;
                        } else {
                            $this->cli->message(
                                sprintf('User "%s" already exists - no changes needed', $username),
                                'cli.warning'
                            );
                            $skipped++;
                        }
                    } else {
                        // User doesn't exist - create
                        $password = $item['password'] ?? null;

                        if (!$password) {
                            $this->cli->message(
                                sprintf('User "%s" has no password - cannot create', $username),
                                'cli.warning'
                            );
                            $skipped++;
                            continue;
                        }

                        $this->apiClient->createUser($username, $password);

                        // Handle identities for new user
                        $identityResult = $this->handleIdentities($username, $item['identities'] ?? []);

                        $msg = sprintf('Created user: %s with %d identities', $username, $identityResult['created']);
                        if ($identityResult['errors'] > 0) {
                            $msg .= sprintf(' (%d errors)', $identityResult['errors']);
                        }
                        $this->cli->message($msg, 'cli.success');
                        $created++;
                    }
                } else {
                    $this->cli->message(
                        sprintf('Invalid state "%s" for user "%s" - must be "present" or "absent"', $state, $username),
                        'cli.error'
                    );
                    $errors++;
                }
            } catch (RuntimeException $e) {
                $this->cli->message(
                    sprintf('Error importing user "%s": %s', $username, $e->getMessage()),
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
     * Handle identity creation/update/deletion for a user
     *
     * @param string $username Username
     * @param array $identities Array of identity data from YAML
     * @return array Stats: ['created' => int, 'updated' => int, 'deleted' => int, 'errors' => int]
     */
    private function handleIdentities(string $username, array $identities): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0];

        if (empty($identities)) {
            return $stats;
        }

        // Get current identities
        try {
            $currentIdentities = $this->apiClient->listIdentities($username);
            $currentByIndex = [];
            foreach ($currentIdentities->identities as $identity) {
                $currentByIndex[$identity->index] = $identity;
            }
        } catch (RuntimeException $e) {
            $this->cli->message(
                sprintf('Warning: Failed to list identities for user "%s": %s', $username, $e->getMessage()),
                'cli.warning'
            );
            $stats['errors']++;
            return $stats;
        }

        foreach ($identities as $identityData) {
            $state = $identityData['state'] ?? 'present';
            $identityId = $identityData['id'] ?? null;
            $isDefault = !empty($identityData['default']);

            if (!$identityId) {
                continue;
            }

            // Strip control fields from identity data before sending to API
            // TODO: Consider revising YAML schema to separate control fields from data
            $apiData = $identityData;
            unset($apiData['state']);
            unset($apiData['default']);

            // Find existing identity by id
            $existingIndex = null;
            foreach ($currentByIndex as $index => $identity) {
                if ($identity->id === $identityId) {
                    $existingIndex = $index;
                    break;
                }
            }

            if ($state === 'absent') {
                if ($existingIndex !== null) {
                    try {
                        $this->apiClient->deleteIdentity($username, $existingIndex);
                        $stats['deleted']++;
                    } catch (RuntimeException $e) {
                        $this->cli->message(
                            sprintf(
                                'Warning: Failed to delete identity "%s" for user "%s": %s',
                                $identityId,
                                $username,
                                $e->getMessage()
                            ),
                            'cli.warning'
                        );
                        $stats['errors']++;
                    }
                }
            } elseif ($state === 'present') {
                if ($existingIndex !== null) {
                    // Update existing identity
                    try {
                        $this->apiClient->updateIdentity($username, $existingIndex, $apiData);
                        $stats['updated']++;

                        if ($isDefault) {
                            try {
                                $this->apiClient->setDefaultIdentity($username, $existingIndex);
                            } catch (RuntimeException $e) {
                                $this->cli->message(
                                    sprintf(
                                        'Warning: Failed to set identity "%s" as default for user "%s": %s',
                                        $identityId,
                                        $username,
                                        $e->getMessage()
                                    ),
                                    'cli.warning'
                                );
                                $stats['errors']++;
                            }
                        }
                    } catch (RuntimeException $e) {
                        $this->cli->message(
                            sprintf(
                                'Warning: Failed to update identity "%s" for user "%s": %s',
                                $identityId,
                                $username,
                                $e->getMessage()
                            ),
                            'cli.warning'
                        );
                        $stats['errors']++;
                    }
                } else {
                    // Create new identity
                    try {
                        $newIdentity = $this->apiClient->createIdentity($username, $apiData);
                        $stats['created']++;

                        if ($isDefault) {
                            try {
                                $this->apiClient->setDefaultIdentity($username, $newIdentity->index);
                            } catch (RuntimeException $e) {
                                $this->cli->message(
                                    sprintf(
                                        'Warning: Failed to set identity "%s" as default for user "%s": %s',
                                        $identityId,
                                        $username,
                                        $e->getMessage()
                                    ),
                                    'cli.warning'
                                );
                                $stats['errors']++;
                            }
                        }
                    } catch (RuntimeException $e) {
                        $this->cli->message(
                            sprintf(
                                'Warning: Failed to create identity "%s" for user "%s": %s',
                                $identityId,
                                $username,
                                $e->getMessage()
                            ),
                            'cli.warning'
                        );
                        $stats['errors']++;
                    }
                }
            }
        }

        return $stats;
    }
}
