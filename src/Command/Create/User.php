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
 * Create User command
 *
 * Creates a new user account interactively or via CLI flags.
 * Requires Admin API endpoint access.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class User implements Module, ModuleUsage
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
                '--username',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Username (required)',
                ]
            ),
            new Option(
                '--password',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Password (required)',
                ]
            ),
            new Option(
                '--fullname',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Full name (optional)',
                ]
            ),
            new Option(
                '--email',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Email address (optional)',
                ]
            ),
            new Option(
                '--auto-password',
                [
                    'action' => 'store_true',
                    'help' => 'Automatically generate random password (skip prompt)',
                ]
            ),
            new Option(
                '--skip-identity',
                [
                    'action' => 'store_true',
                    'help' => 'Skip creating default identity (useful if prefs not configured)',
                ]
            ),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1 || $argv[0] !== 'user') {
            return false;
        }

        // Check target capability and create API client
        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        [$opts, $args] = $this->handleCommandline($argv);

        // Gather user data (interactive or from options)
        $userData = $this->gatherUserData($opts);

        // Validate data
        $this->validateUserData($userData);

        // Check if user exists
        if ($this->userExists($userData['username'])) {
            $this->cli->fatal(
                sprintf(
                    "Error: User '%s' already exists\n"
                    . "Use 'hordectl patch user %s <password>' to update the password",
                    $userData['username'],
                    $userData['username']
                )
            );
        }

        // Create user
        try {
            $skipIdentity = $opts->skip_identity ?? false;
            $this->createUser($userData, $skipIdentity);
            $this->output->ok(
                sprintf("User '%s' created successfully", $userData['username'])
            );
        } catch (RuntimeException $e) {
            $this->cli->fatal("Error creating user: " . $e->getMessage());
        }

        return true;
    }

    protected function gatherUserData($opts): array
    {
        $data = [];
        // Check if stdin is a TTY (interactive terminal)
        $interactive = (function_exists('posix_isatty') && posix_isatty(STDIN))
                    || (function_exists('stream_isatty') && stream_isatty(STDIN));

        // Username
        if (!empty($opts->username)) {
            $data['username'] = $opts->username;
        } elseif (!$interactive) {
            $this->cli->fatal("Error: --username required (not running interactively)");
        } else {
            $this->cli->writeln();
            $this->cli->writeln("Create new user");
            $this->cli->writeln("===============");
            $this->cli->writeln();
            $data['username'] = $this->cli->prompt('Username:');
        }

        // Password
        if (!empty($opts->password)) {
            $data['password'] = $opts->password;
        } elseif ($opts->auto_password ?? false) {
            $data['password'] = $this->generateRandomPassword();
            $this->cli->writeln("Generated password: " . $data['password']);
        } elseif (!$interactive) {
            $this->cli->fatal("Error: --password or --auto-password required (not running interactively)");
        } else {
            $data['password'] = $this->promptPassword();
        }

        // Optional fields - only prompt if interactive and not already provided
        if (!empty($opts->fullname)) {
            $data['fullname'] = $opts->fullname;
        } elseif ($interactive) {
            $data['fullname'] = $this->promptOptional('Full name');
        } else {
            $data['fullname'] = null;
        }

        if (!empty($opts->email)) {
            $data['email'] = $opts->email;
        } elseif ($interactive) {
            $data['email'] = $this->promptOptional('Email');
        } else {
            $data['email'] = null;
        }

        return $data;
    }

    protected function promptPassword(): string
    {
        $password = $this->cli->passwordPrompt('Password:');
        $confirm = $this->cli->passwordPrompt('Confirm password:');

        if ($password !== $confirm) {
            $this->cli->fatal("Error: Passwords do not match");
        }

        return $password;
    }

    protected function promptOptional(string $label): ?string
    {
        $value = $this->cli->prompt($label . ' (optional):');
        return !empty($value) ? $value : null;
    }

    protected function validateUserData(array $data): void
    {
        // Username validation
        if (empty($data['username'])) {
            $this->cli->fatal("Error: Username cannot be empty");
        }

        // Password validation
        if (empty($data['password'])) {
            $this->cli->fatal("Error: Password cannot be empty");
        }

        // Note: No password complexity validation - backend enforces its own policy

        // Email validation (basic sanity check only)
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->cli->fatal("Error: Invalid email address");
        }
    }

    protected function userExists(string $username): bool
    {
        try {
            $this->apiClient->getUser($username);
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    protected function createUser(array $userData, bool $skipIdentity = false): void
    {
        // AdminApiClient::createUser currently only accepts username and password
        // TODO: Extend API to support fullname and email
        $this->apiClient->createUser(
            $userData['username'],
            $userData['password'],
            $skipIdentity
        );
    }

    protected function generateRandomPassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-/!@#$%^&*';
        $password = '';
        $maxIndex = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $maxIndex)];
        }

        return $password;
    }

    public function getUsage(): string
    {
        return 'create user [options]';
    }

    public function getTitle(): string
    {
        return 'user';
    }

    public function getUsageDescription(): array
    {
        return [
            'Create a new user account',
            '',
            'Usage:',
            '  hordectl create user                    # Interactive mode',
            '  hordectl create user --username admin --password secret',
            '',
            'Options:',
            '  --username <name>       Username (required)',
            '  --password <pass>       Password (required, or use --auto-password)',
            '  --auto-password         Generate random password automatically',
            '  --skip-identity         Skip creating default identity',
            '  --fullname <name>       Full name (optional)',
            '  --email <address>       Email address (optional)',
            '',
            'Interactive mode: Prompts for missing fields when running from TTY.',
            'Non-interactive: Requires all mandatory fields via CLI flags.',
            '',
            'Examples:',
            '  hordectl create user',
            '  hordectl create user --username admin --password SecurePass123',
            '  hordectl create user --username testuser --auto-password',
            '  hordectl create user --username alice --password secret --email alice@example.com',
        ];
    }
}
