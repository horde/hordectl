<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\Create;

use Horde\Argv\Option;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use RuntimeException;

/**
 * Create Group command
 *
 * Creates a new group interactively or via CLI flags.
 * Requires Admin API endpoint access.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Group implements Module, ModuleUsage
{
    use HordectlModuleTrait;
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
        $this->_parser->allowInterspersedArgs = false;
    }

    public function getBaseOptions(): array
    {
        return [
            new Option(
                '--name',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Group name (required)',
                ]
            ),
            new Option(
                '--description',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Group description (optional)',
                ]
            ),
            new Option(
                '--email',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Group email (optional)',
                ]
            ),
            new Option(
                '--members',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Comma-separated list of usernames (optional)',
                ]
            ),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1 || $argv[0] !== 'group') {
            return false;
        }

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        [$opts, $args] = $this->handleCommandline($argv);

        // Gather group data
        $groupData = $this->gatherGroupData($opts);

        // Validate data
        $this->validateGroupData($groupData);

        // Check if group exists
        if ($this->groupExists($groupData['name'])) {
            $this->cli->fatal(
                sprintf("Error: Group '%s' already exists", $groupData['name'])
            );
        }

        // Create group
        try {
            $this->createGroup($groupData);
            $this->output->ok(
                sprintf("Group '%s' created successfully", $groupData['name'])
            );

            // Add members if specified
            if (!empty($groupData['members'])) {
                $this->addMembers($groupData['name'], $groupData['members']);
            }
        } catch (RuntimeException $e) {
            $this->cli->fatal("Error creating group: " . $e->getMessage());
        }

        return true;
    }

    protected function gatherGroupData($opts): array
    {
        $data = [];
        // Check if stdin is a TTY (interactive terminal)
        $interactive = (function_exists('posix_isatty') && posix_isatty(STDIN))
                    || (function_exists('stream_isatty') && stream_isatty(STDIN));

        // Group name
        if (!empty($opts->name)) {
            $data['name'] = $opts->name;
        } elseif (!$interactive) {
            $this->cli->fatal("Error: --name required (not running interactively)");
        } else {
            $this->cli->writeln();
            $this->cli->writeln("Create new group");
            $this->cli->writeln("================");
            $this->cli->writeln();
            $data['name'] = $this->cli->prompt('Group name:');
        }

        // Optional fields - only prompt if interactive and not already provided
        if (!empty($opts->description)) {
            $data['description'] = $opts->description;
        } elseif ($interactive) {
            $data['description'] = $this->promptOptional('Description');
        } else {
            $data['description'] = null;
        }

        if (!empty($opts->email)) {
            $data['email'] = $opts->email;
        } elseif ($interactive) {
            $data['email'] = $this->promptOptional('Email');
        } else {
            $data['email'] = null;
        }

        if (!empty($opts->members)) {
            $membersInput = $opts->members;
        } elseif ($interactive) {
            $membersInput = $this->promptOptional('Members (comma-separated)');
        } else {
            $membersInput = null;
        }

        $data['members'] = $membersInput ? array_map('trim', explode(',', $membersInput)) : [];

        return $data;
    }

    protected function promptOptional(string $label): ?string
    {
        $value = $this->cli->prompt($label . ' (optional):');
        return !empty($value) ? $value : null;
    }

    protected function validateGroupData(array $data): void
    {
        if (empty($data['name'])) {
            $this->cli->fatal("Error: Group name cannot be empty");
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->cli->fatal("Error: Invalid email address");
        }
    }

    protected function groupExists(string $name): bool
    {
        try {
            $this->apiClient->getGroup($name);
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    protected function createGroup(array $groupData): void
    {
        // AdminApiClient::createGroup currently only accepts name
        // TODO: Extend API to support email and description
        $this->apiClient->createGroup($groupData['name']);
    }

    protected function addMembers(string $groupName, array $members): void
    {
        $added = [];
        $skipped = [];

        foreach ($members as $username) {
            try {
                $this->apiClient->addGroupMember($groupName, $username);
                $added[] = $username;
            } catch (RuntimeException $e) {
                $this->output->warn(
                    sprintf("Could not add user '%s': %s", $username, $e->getMessage())
                );
                $skipped[] = $username;
            }
        }

        if (!empty($added)) {
            $this->output->ok(
                sprintf("Added %d member(s): %s", count($added), implode(', ', $added))
            );
        }

        if (!empty($skipped)) {
            $this->output->warn(
                sprintf("Skipped %d member(s): %s", count($skipped), implode(', ', $skipped))
            );
        }
    }

    public function getUsage(): string
    {
        return 'create group [options]';
    }

    public function getTitle(): string
    {
        return 'group';
    }

    public function getUsageDescription(): array
    {
        return [
            'Create a new group',
            '',
            'Usage:',
            '  hordectl create group                   # Interactive mode',
            '  hordectl create group --name admins --members alice,bob',
            '',
            'Options:',
            '  --name <name>           Group name (required)',
            '  --description <text>    Group description (optional)',
            '  --email <address>       Group email (optional)',
            '  --members <users>       Comma-separated usernames (optional)',
            '',
            'Interactive mode: Prompts for missing fields when running from TTY.',
            'Non-interactive: Requires all mandatory fields via CLI flags.',
            '',
            'Examples:',
            '  hordectl create group',
            '  hordectl create group --name administrators',
            '  hordectl create group --name developers --members alice,bob,charlie',
        ];
    }
}
